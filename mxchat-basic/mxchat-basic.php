<?php
/**
 * Plugin Name: MxChat
 * Plugin URI: https://mxchat.ai/
 * Description: AI chatbot for WordPress with OpenAI, Claude, xAI, DeepSeek, live agent, PDF uploads, WooCommerce, and training on website data.
 * Version: 3.2.21
 * Author: MxChat
 * Author URI: https://mxchat.ai
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mxchat
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}


if (!defined('MXCHAT_DEV_MODE')) {
    define('MXCHAT_DEV_MODE', false);
}

if (!defined('MXCHAT_VERSION')) {
    $plugin_data = get_file_data(__FILE__, array('Version' => 'Version'), 'plugin');
    $version = $plugin_data['Version'];
    // MXCHAT_BASE_VERSION: the plain header version, stable across requests even in
    // dev mode. Use it for anything PERSISTED or COMPARED (the stored
    // mxchat_plugin_version option and the migration gate in
    // mxchat_check_for_update). MXCHAT_VERSION keeps the time() suffix in dev for
    // ASSET cache-busting only — persisting the suffixed value made the version
    // comparison churn every request, re-running the full activation/migration
    // suite per page load on dev installs.
    define('MXCHAT_BASE_VERSION', $version);
    if (MXCHAT_DEV_MODE) {
        $version .= '.' . time();
    }
    define('MXCHAT_VERSION', $version);
}

/**
 * Default confidence floor for the in-chat YouTube card, as a percentage
 * (plan-mxchat-20260813-f52492). Higher than the site-wide Similarity
 * Threshold default of 35 by design — see MxChat_Utils::video_embed_threshold().
 * Declared here so the gate, the admin field and the tests all read ONE number.
 */
if (!defined('MXCHAT_VIDEO_EMBED_THRESHOLD_DEFAULT')) {
    define('MXCHAT_VIDEO_EMBED_THRESHOLD_DEFAULT', 55);
}

/**
 * One-time install stamp: records the plain version this site first ran, so
 * behavior defaults can differ between fresh installs and upgrades without
 * touching anyone's stored settings. Mirrors mxchat-mcp's 1.0.7 stamp shape
 * (plan 7b578e); first consumer is the "Strip unapproved links" default
 * (plan 58f8b4). Runs at init priority 1 — BEFORE initialize_default_options
 * (init 20) writes mxchat_options on a fresh site's first request, because
 * that option's pre-existing presence is how an upgrade is recognized.
 */
function mxchat_stamp_install_version() {
    if (get_option('mxchat_installed_at_version', '') !== '') {
        return;
    }
    $existing = get_option('mxchat_options', false) !== false;
    $version = defined('MXCHAT_BASE_VERSION') ? MXCHAT_BASE_VERSION : '0.0.0';
    update_option('mxchat_installed_at_version', $existing ? 'legacy' : $version, false);
}
add_action('init', 'mxchat_stamp_install_version', 1);

/**
 * Stamp-derived default for the "Strip unapproved links" toggle (plan 58f8b4,
 * option-c split of the old Citation Links conflation): 'on' only for installs
 * born at 3.2.20+. A missing or 'legacy' stamp means the site predates the
 * setting — keep 'off' so no existing site's links start vanishing on update.
 */
function mxchat_strip_unapproved_links_default() {
    $stamp = get_option('mxchat_installed_at_version', '');
    if ($stamp === '' || $stamp === 'legacy') {
        return 'off';
    }
    return version_compare($stamp, '3.2.20', '>=') ? 'on' : 'off';
}

/**
 * Effective state of "Strip unapproved links": an explicitly saved option
 * always wins; until one exists the install-stamp default governs. Read via
 * a fresh get_option on purpose — the response URL guard runs late in the
 * request and must see a value saved moments earlier.
 */
function mxchat_strip_unapproved_links_enabled() {
    $opts = get_option('mxchat_options', array());
    if (is_array($opts) && isset($opts['strip_unapproved_links_toggle'])) {
        return $opts['strip_unapproved_links_toggle'] === 'on';
    }
    return mxchat_strip_unapproved_links_default() === 'on';
}

/**
 * Honest, versioned User-Agent for MXChat remote-content ingestion fetches
 * (Knowledge Base PDF import, URL import, sitemap / website crawl).
 *
 * WAF rulesets (SiteGround/ModSecurity, Wordfence, Cloudflare managed rules)
 * flag stale spoofed-browser UAs as scrapers and return 403 — which silently
 * broke the single most common KB source: self-hosted media on the site's own
 * domain. A truthful crawler identifier is the industry norm for well-behaved
 * bots and lets a site owner allowlist "MXChatBot" in their WAF. Filterable so
 * a locked-down host can supply a different string without a code change.
 */
if (!function_exists('mxchat_ingest_user_agent')) {
    function mxchat_ingest_user_agent() {
        $version = defined('MXCHAT_VERSION') ? MXCHAT_VERSION : '1.0';
        $ua = 'MXChatBot/' . $version . ' (+https://mxchat.ai/bot)';
        return apply_filters('mxchat_ingest_user_agent', $ua);
    }
}

function mxchat_load_textdomain() {
    $domain = 'mxchat';
    $locale = determine_locale();

    // First, try to load from /wp-content/languages/plugins/ (preserved during updates)
    $mo_file = WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale . '.mo';
    if (file_exists($mo_file)) {
        load_textdomain($domain, $mo_file);
        return;
    }

    // Fallback to plugin's /languages directory
    load_plugin_textdomain($domain, false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('init', 'mxchat_load_textdomain');

/**
 * One-time migration: gemini-3-pro-preview was shut down by Google on March 9, 2026.
 * Existing installs with the dead ID get auto-remapped to gemini-3.1-pro-preview
 * (Google's official migration target) the first time admin_init fires after update.
 */
add_action('admin_init', function () {
    if (get_option('mxchat_gemini_3_remap_done')) {
        return;
    }
    $opts = get_option('mxchat_options');
    if (is_array($opts) && isset($opts['model']) && $opts['model'] === 'gemini-3-pro-preview') {
        $opts['model'] = 'gemini-3.1-pro-preview';
        update_option('mxchat_options', $opts);
    }
    if (is_array($opts) && isset($opts['content_model']) && $opts['content_model'] === 'gemini-3-pro-preview') {
        $opts['content_model'] = 'gemini-3.1-pro-preview';
        update_option('mxchat_options', $opts);
    }
    update_option('mxchat_gemini_3_remap_done', 1);
});

/**
 * One-time migration: the Grok 2 family was retired by xAI (grok-2, grok-2-1212,
 * grok-2-latest, grok-2-vision-1212 all return 400 "Model not found"). Existing
 * installs with the dead ID get auto-remapped to grok-4-1-fast-non-reasoning
 * (modern, fast, broadly available) the first time admin_init fires after update.
 */
add_action('admin_init', function () {
    if (get_option('mxchat_grok_2_remap_done')) {
        return;
    }
    $opts = get_option('mxchat_options');
    if (is_array($opts) && isset($opts['model']) && $opts['model'] === 'grok-2') {
        $opts['model'] = 'grok-4-1-fast-non-reasoning';
        update_option('mxchat_options', $opts);
    }
    if (is_array($opts) && isset($opts['content_model']) && $opts['content_model'] === 'grok-2') {
        $opts['content_model'] = 'grok-4-1-fast-non-reasoning';
        update_option('mxchat_options', $opts);
    }
    update_option('mxchat_grok_2_remap_done', 1);
});

/**
 * One-time migration: Anthropic retired the Claude 4 (2025-05-14) snapshots on
 * June 15, 2026 — claude-opus-4-20250514 and claude-sonnet-4-20250514 now return
 * an API error. Existing installs with a dead ID get auto-remapped to the current
 * equivalents Anthropic recommends (Opus 4.8 / Sonnet 4.6) the first time admin_init
 * fires after update. Mirrors the gemini-3-pro-preview / grok-2 rescues above.
 */
add_action('admin_init', function () {
    if (get_option('mxchat_claude_4_retire_remap_done')) {
        return;
    }
    $map = array(
        'claude-opus-4-20250514'   => 'claude-opus-4-8',
        'claude-sonnet-4-20250514' => 'claude-sonnet-4-6',
    );
    $opts = get_option('mxchat_options');
    if (is_array($opts)) {
        $changed = false;
        if (isset($opts['model']) && isset($map[$opts['model']])) {
            $opts['model'] = $map[$opts['model']];
            $changed = true;
        }
        if (isset($opts['content_model']) && isset($map[$opts['content_model']])) {
            $opts['content_model'] = $map[$opts['content_model']];
            $changed = true;
        }
        if ($changed) {
            update_option('mxchat_options', $opts);
        }
    }
    update_option('mxchat_claude_4_retire_remap_done', 1);
});

/**
 * Exclude MxChat assets from caching plugin optimizations
 *
 * This prevents issues with WP Rocket, LiteSpeed Cache, Autoptimize, WP Super Cache,
 * W3 Total Cache, SG Optimizer, and similar plugins that may break the chatbot by
 * removing "unused" CSS, minifying/combining JS, or deferring/delaying jQuery.
 *
 * Both chat-script.js and floating-script.js depend on jQuery, so jQuery must also
 * be excluded from any optimization that changes load order or timing.
 */

// ── WP Rocket ────────────────────────────────────────────────────────────────

// Exclude from Remove Unused CSS (RUCSS)
add_filter('rocket_rucss_inline_atts_exclusions', function($exclusions) {
    if (!is_array($exclusions)) $exclusions = array();
    $exclusions[] = 'mxchat';
    return $exclusions;
});

// Exclude CSS from minification/combination
add_filter('rocket_exclude_css', function($excluded) {
    if (!is_array($excluded)) $excluded = array();
    $excluded[] = '/plugins/mxchat-basic/css/chat-style.css';
    return $excluded;
});

// Exclude JS from minification/combination
add_filter('rocket_exclude_js', function($excluded) {
    if (!is_array($excluded)) $excluded = array();
    $excluded[] = '/plugins/mxchat-basic/js/chat-script.js';
    $excluded[] = '/plugins/mxchat-basic/js/floating-script.js';
    $excluded[] = '/jquery-core';
    $excluded[] = '/jquery.min.js';
    $excluded[] = '/jquery.js';
    $excluded[] = '/jquery-migrate';
    return $excluded;
});

// Exclude JS from defer
add_filter('rocket_exclude_defer_js', function($excluded) {
    if (!is_array($excluded)) $excluded = array();
    $excluded[] = '/plugins/mxchat-basic/js/chat-script.js';
    $excluded[] = '/plugins/mxchat-basic/js/floating-script.js';
    $excluded[] = '/jquery-core';
    $excluded[] = '/jquery.min.js';
    $excluded[] = '/jquery.js';
    $excluded[] = '/jquery-migrate';
    return $excluded;
});

// Exclude from delay JS execution
add_filter('rocket_delay_js_exclusions', function($excluded) {
    if (!is_array($excluded)) $excluded = array();
    $excluded[] = 'mxchat';
    $excluded[] = 'chat-script';
    $excluded[] = 'floating-script';
    $excluded[] = '/jquery-core';
    $excluded[] = '/jquery.min.js';
    $excluded[] = '/jquery.js';
    $excluded[] = '/jquery-migrate';
    return $excluded;
});

// ── LiteSpeed Cache ──────────────────────────────────────────────────────────

// Exclude CSS from optimization
add_filter('litespeed_optimize_css_excludes', function($excluded) {
    if (!is_array($excluded)) $excluded = array();
    $excluded[] = 'chat-style.css';
    $excluded[] = 'mxchat';
    return $excluded;
});

// Exclude from UCSS (Unique CSS) - prevents LiteSpeed from stripping "unused" MxChat CSS
add_filter('litespeed_ucss_whitelist', function($whitelist) {
    if (!is_array($whitelist)) $whitelist = array();
    $whitelist[] = '.mxchat-chatbot-wrapper';
    $whitelist[] = '.floating-chatbot';
    $whitelist[] = '.floating-chatbot-button';
    $whitelist[] = '.chatbot-top-bar';
    $whitelist[] = '.mxchat-chatbot';
    $whitelist[] = '.chat-container';
    $whitelist[] = '.chat-box';
    $whitelist[] = '.bot-message';
    $whitelist[] = '.input-container';
    $whitelist[] = '.chat-input';
    $whitelist[] = '.send-button';
    $whitelist[] = '.pre-chat-message';
    $whitelist[] = '.mxchat-popular-questions';
    $whitelist[] = '.chat-toolbar';
    $whitelist[] = '.exit-chat';
    $whitelist[] = '.email-blocker';
    return $whitelist;
});

// Exclude CSS from CCSS (Critical CSS) generation
add_filter('litespeed_optm_ccss_exc', function($excluded) {
    if (!is_array($excluded)) $excluded = array();
    $excluded[] = 'chat-style.css';
    $excluded[] = 'mxchat';
    return $excluded;
});

// Exclude JS from defer
add_filter('litespeed_optm_js_defer_exc', function($excluded) {
    if (!is_array($excluded)) $excluded = array();
    $excluded[] = 'chat-script.js';
    $excluded[] = 'floating-script.js';
    $excluded[] = 'mxchat';
    $excluded[] = 'jquery.min.js';
    $excluded[] = 'jquery.js';
    return $excluded;
});

// Exclude JS from combining
add_filter('litespeed_optm_js_exc', function($excluded) {
    if (!is_array($excluded)) $excluded = array();
    $excluded[] = 'chat-script.js';
    $excluded[] = 'floating-script.js';
    $excluded[] = 'mxchat';
    $excluded[] = 'jquery.min.js';
    $excluded[] = 'jquery.js';
    return $excluded;
});

// Exclude JS from delayed execution
add_filter('litespeed_optm_js_delay_exc', function($excluded) {
    if (!is_array($excluded)) $excluded = array();
    $excluded[] = 'chat-script.js';
    $excluded[] = 'floating-script.js';
    $excluded[] = 'mxchat';
    return $excluded;
});

// Exclude from Guest Mode optimization
add_filter('litespeed_guest_optm_exc', function($excluded) {
    if (!is_array($excluded)) $excluded = array();
    $excluded[] = 'mxchat';
    $excluded[] = 'chat-style';
    $excluded[] = 'chat-script';
    $excluded[] = 'floating-script';
    return $excluded;
});

// ── Autoptimize ──────────────────────────────────────────────────────────────

// Exclude CSS from optimization (comma-separated strings)
add_filter('autoptimize_filter_css_exclude', function($excluded) {
    if (!is_string($excluded)) $excluded = '';
    return $excluded . ', mxchat, chat-style.css';
});

// Exclude JS from optimization (comma-separated strings)
add_filter('autoptimize_filter_js_exclude', function($excluded) {
    if (!is_string($excluded)) $excluded = '';
    return $excluded . ', mxchat, chat-script.js, floating-script.js, jquery.min.js, jquery.js';
});

// ── SG Optimizer (SiteGround) ────────────────────────────────────────────────

add_filter('sgo_js_minify_exclude', function($excluded) {
    if (!is_array($excluded)) $excluded = array();
    $excluded[] = 'chat-script.js';
    $excluded[] = 'floating-script.js';
    $excluded[] = 'jquery.min.js';
    return $excluded;
});

add_filter('sgo_javascript_combine_exclude', function($excluded) {
    if (!is_array($excluded)) $excluded = array();
    $excluded[] = 'chat-script.js';
    $excluded[] = 'floating-script.js';
    $excluded[] = 'jquery.min.js';
    return $excluded;
});

add_filter('sgo_js_async_exclude', function($excluded) {
    if (!is_array($excluded)) $excluded = array();
    $excluded[] = 'chat-script.js';
    $excluded[] = 'floating-script.js';
    $excluded[] = 'jquery.min.js';
    return $excluded;
});

// ── W3 Total Cache ───────────────────────────────────────────────────────────

add_filter('w3tc_minify_js_do_tag_minification', function($do_minify, $script_tag, $file) {
    if (strpos($file, 'chat-script.js') !== false ||
        strpos($file, 'floating-script.js') !== false ||
        strpos($file, 'jquery.min.js') !== false ||
        strpos($file, 'jquery.js') !== false) {
        return false;
    }
    return $do_minify;
}, 10, 3);

// ── WP Super Cache ──────────────────────────────────────────────────────────

add_filter('wpsc_rejected_uri', function($rejected) {
    if (!is_array($rejected)) $rejected = array();
    $rejected[] = 'wp-admin/admin-ajax.php';
    return $rejected;
});

// ── Page-cache bypass for chat AJAX (companion to the 3.2.6 nonce-race hotfix)
// Each cache plugin gets its own filter export so that visitors hitting an
// edge-cached page never receive cached chat-AJAX responses. The chat send /
// stream send / file upload all POST to /wp-admin/admin-ajax.php with
// `action=mxchat_*`. Without these exports, a cache plugin can stale a response
// and break the per-session nonce flow on the first message.

// WP Rocket — `rocket_cache_reject_uri` takes a flat array of regex strings.
add_filter('rocket_cache_reject_uri', function($uris) {
    if (!is_array($uris)) $uris = array();
    $uris[] = '/wp-admin/admin-ajax\.php\?action=mxchat_.*';
    return $uris;
});

// LiteSpeed Cache — `litespeed_cache_no_cache_for_request` short-circuits
// caching when the request matches our chat-AJAX pattern.
add_filter('litespeed_cache_no_cache_for_request', function($no_cache) {
    if ($no_cache) return $no_cache;
    if (!empty($_SERVER['REQUEST_URI']) &&
        strpos($_SERVER['REQUEST_URI'], '/wp-admin/admin-ajax.php') !== false &&
        !empty($_REQUEST['action']) &&
        strpos((string) $_REQUEST['action'], 'mxchat_') === 0) {
        return true;
    }
    return $no_cache;
});

// W3 Total Cache — `w3tc_pgcache_request_skip_uri` flips page-cache off when
// the URI matches.
add_filter('w3tc_pgcache_request_skip_uri', function($skip) {
    if ($skip) return $skip;
    if (!empty($_SERVER['REQUEST_URI']) &&
        strpos($_SERVER['REQUEST_URI'], '/wp-admin/admin-ajax.php') !== false &&
        !empty($_REQUEST['action']) &&
        strpos((string) $_REQUEST['action'], 'mxchat_') === 0) {
        return true;
    }
    return $skip;
});

// FlyingPress — `flying_press_cacheable` takes a boolean and is run per
// request. Same pattern as LiteSpeed / W3TC.
add_filter('flying_press_cacheable', function($cacheable) {
    if (!$cacheable) return $cacheable;
    if (!empty($_SERVER['REQUEST_URI']) &&
        strpos($_SERVER['REQUEST_URI'], '/wp-admin/admin-ajax.php') !== false &&
        !empty($_REQUEST['action']) &&
        strpos((string) $_REQUEST['action'], 'mxchat_') === 0) {
        return false;
    }
    return $cacheable;
});

// Include classes with error handling
function mxchat_include_classes() {
    $class_files = array(
        'includes/class-mxchat-model-catalog.php',
        'includes/class-mxchat-model-liveness.php',
        'includes/class-mxchat-session-store.php',
        'includes/class-mxchat-live-agent-schedule.php',
        'includes/class-mxchat-tool-registry.php',
        'includes/class-mxchat-integrator.php',
        'includes/class-mxchat-admin.php',
        'includes/class-mxchat-public.php',
        'includes/class-mxchat-block.php',
        'includes/class-mxchat-elementor.php',
        'includes/class-mxchat-utils.php',
        'includes/class-mxchat-user.php',
        'includes/class-mxchat-privacy.php',
        'includes/class-mxchat-meta-box.php',
        'includes/class-mxchat-chunker.php',
        'includes/class-mxchat-word-handler.php',
        'includes/class-mxchat-content-generator.php',
        'includes/class-mxchat-cache-purge.php',
        'includes/class-mxchat-editor-assistant.php',
        'includes/class-rest-api.php',
        'admin/class-ajax-handler.php',
        'admin/class-pinecone-manager.php',
        'admin/class-knowledge-manager.php',
        'admin/class-vectorstore-manager.php'
    );

    foreach ($class_files as $file) {
        $file_path = plugin_dir_path(__FILE__) . $file;
        if (file_exists($file_path)) {
            require_once $file_path;
        } else {
            //error_log('MxChat: Missing class file - ' . $file);
        }
    }

    // Register the native function-calling admin-post save handler (a41dee).
    if (class_exists('MxChat_Tool_Registry')) {
        MxChat_Tool_Registry::init();
    }

    // GDPR: register with WP's personal-data export/erase tools (b81e42).
    if (class_exists('MxChat_Privacy')) {
        MxChat_Privacy::init();
    }

    // Per-session state store: retention cron + the cron-independent
    // migration drain off admin_init (b64b77).
    if (class_exists('MxChat_Session_Store')) {
        MxChat_Session_Store::init();
    }

    // Daily model-liveness check + its warning notice (b65e8d). Read-only and
    // fail-open: one listing request per in-use provider per day, none at all
    // when no key is stored.
    if (class_exists('MxChat_Model_Liveness')) {
        MxChat_Model_Liveness::init();
    }

    // Gutenberg chatbot block (plan-95dd1e): a click-to-place wrapper over the
    // [mxchat_chatbot] shortcode. Registers on init; no-op below WP 5.0.
    if (class_exists('MxChat_Block')) {
        MxChat_Block::init();
    }

    // Elementor chatbot widget (plan-95dd1e part 2): registered ONLY inside
    // elementor/widgets/register (Elementor >= 3.5) — with Elementor absent or
    // older, the hook never fires and nothing further loads.
    if (class_exists('MxChat_Elementor')) {
        MxChat_Elementor::init();
    }

    // Editor Assistant — free, OFF-by-default block-editor AI actions (plan-8cb0cb).
    // init() wires REST + streaming AJAX + sidebar enqueue ONLY when the
    // mxchat_editor_assistant_enabled option is 'on'; otherwise zero footprint.
    if (class_exists('MxChat_Editor_Assistant')) {
        MxChat_Editor_Assistant::init();
    }

    // OpenAI Vector Store write path (plan-15b5c6): import/sync AJAX, the
    // import cron tick, the pending-delete sweeper, and the WP-CLI command
    // all register in the constructor. The sync itself only runs when the
    // sync toggle + store ID + OpenAI key are all present.
    if (class_exists('MxChat_Vectorstore_Manager')) {
        MxChat_Vectorstore_Manager::get_instance();
    }

    // Admin pages that aren't classes (procedural include).
    if (is_admin()) {
        $admin_api_page = plugin_dir_path(__FILE__) . 'includes/admin-api-page.php';
        if (file_exists($admin_api_page)) {
            require_once $admin_api_page;
        }
        // f7c7d4 renamed this file admin-dashboard-page.php → admin-onboarding-page.php.
        // The require MUST live here (admin bootstrap) and not just inside
        // mxchat_add_plugin_page() on the admin_menu hook — admin_menu does NOT
        // fire on admin-ajax.php requests, so the wizard's AJAX handlers
        // (plan-905439: mxchat_onboarding_kb_status / save_step / mark_step /
        // auto_graduate + the f7c7d4 dismiss handler) would never register.
        $admin_onboarding_page = plugin_dir_path(__FILE__) . 'includes/admin-onboarding-page.php';
        if (file_exists($admin_onboarding_page)) {
            require_once $admin_onboarding_page;
        }
    }
}

/**
 * Lazy-load the PDF parser library only when needed.
 * Avoids loading 44 files on every page request.
 */
function mxchat_load_pdf_parser() {
    if (class_exists('\Smalot\PdfParser\Parser')) {
        return true;
    }
    $autoload_path = plugin_dir_path(__FILE__) . 'includes/pdf-parser/alt_autoload.php';
    if (file_exists($autoload_path)) {
        require_once $autoload_path;
        return true;
    }
    return false;
}

/**
 * Create URL click tracking table
 */
function mxchat_create_url_clicks_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'mxchat_url_clicks';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        session_id varchar(100) NOT NULL,
        clicked_url text NOT NULL,
        message_context text,
        click_timestamp datetime DEFAULT CURRENT_TIMESTAMP,
        user_ip varchar(45),
        user_agent text,
        PRIMARY KEY (id),
        KEY session_id (session_id),
        KEY click_timestamp (click_timestamp)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Create the per-session state table (b64b77).
 *
 * Callable from the activation hook, which can run before plugins_loaded has
 * included the class files — so it loads the class itself when needed.
 */
function mxchat_create_sessions_table() {
    if (!class_exists('MxChat_Session_Store')) {
        $path = plugin_dir_path(__FILE__) . 'includes/class-mxchat-session-store.php';
        if (!file_exists($path)) {
            return false;
        }
        require_once $path;
    }

    return MxChat_Session_Store::create_table();
}

/**
 * FIXED: Robust table creation and column management
 */
function mxchat_create_chat_transcripts_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'mxchat_chat_transcripts';
    $charset_collate = $wpdb->get_charset_collate();
    
    // Create table with ALL columns including user_name from the start
    $sql = "CREATE TABLE $table_name (
        id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
        user_id MEDIUMINT(9) DEFAULT 0,
        session_id VARCHAR(255) NOT NULL,
        role VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        user_email VARCHAR(255) DEFAULT NULL,
        user_name VARCHAR(100) DEFAULT NULL,
        user_identifier VARCHAR(255) DEFAULT NULL,
        originating_page_url TEXT DEFAULT NULL,
        originating_page_title VARCHAR(500) DEFAULT NULL,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id),
        KEY session_id (session_id),
        KEY user_email (user_email),
        KEY timestamp (timestamp)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $result = dbDelta($sql);
    
    // Log the result for debugging
    if (empty($result)) {
        //error_log("MxChat: dbDelta returned empty result for chat transcripts table");
    } else {
        //error_log("MxChat: dbDelta result: " . print_r($result, true));
    }
    
    // IMPORTANT: Ensure all columns exist for existing installations
    mxchat_ensure_all_columns($table_name);
}

/**
 * Ensure all required columns exist (for upgrades)
 */
function mxchat_ensure_all_columns($table_name) {
    global $wpdb;
    
    // First check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    if (!$table_exists) {
        //error_log("MxChat: Table $table_name does not exist, cannot add columns");
        return;
    }
    
    // Define all required columns and their types
    $required_columns = [
        'user_identifier' => 'VARCHAR(255) DEFAULT NULL',
        'user_email' => 'VARCHAR(255) DEFAULT NULL',
        'user_name' => 'VARCHAR(100) DEFAULT NULL',
        'originating_page_url' => 'TEXT DEFAULT NULL',
        'originating_page_title' => 'VARCHAR(500) DEFAULT NULL',
        'rag_context' => 'LONGTEXT DEFAULT NULL'
    ];
    
    // Get existing columns
    $existing_columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");
    if (empty($existing_columns)) {
        //error_log("MxChat: Could not get columns for table $table_name");
        return;
    }
    
    $existing_column_names = array_column($existing_columns, 'Field');
    
    // Add missing columns
    foreach ($required_columns as $column_name => $column_definition) {
        if (!in_array($column_name, $existing_column_names)) {
            $alter_sql = "ALTER TABLE $table_name ADD COLUMN $column_name $column_definition";
            $result = $wpdb->query($alter_sql);
            
            if ($result === false) {
                //error_log("MxChat: Failed to add column $column_name to $table_name. Error: " . $wpdb->last_error);
            } else {
                //error_log("MxChat: Successfully added column $column_name to $table_name");
            }
        }
    }
}

/**
 * Add role restriction column to knowledge base table
 */
function mxchat_add_role_restriction_column() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_system_prompt_content';
    
    // Check if table exists first
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    if (!$table_exists) {
        //error_log("MxChat: System prompt content table does not exist, cannot add role_restriction column");
        return;
    }
    
    // Check if column already exists
    $column_exists = $wpdb->get_results(
        $wpdb->prepare(
            "SHOW COLUMNS FROM {$table_name} LIKE %s",
            'role_restriction'
        )
    );
    
    if (empty($column_exists)) {
        $alter_sql = "ALTER TABLE {$table_name} ADD COLUMN role_restriction VARCHAR(50) DEFAULT 'public' AFTER source_url";
        $result = $wpdb->query($alter_sql);
        
        if ($result === false) {
            //error_log("MxChat: Failed to add role_restriction column. Error: " . $wpdb->last_error);
        } else {
            //error_log("MxChat: Successfully added role_restriction column");
            
            // Set all existing records to 'public' (everyone can access)
            $update_result = $wpdb->query(
                "UPDATE {$table_name} 
                 SET role_restriction = 'public' 
                 WHERE role_restriction IS NULL OR role_restriction = ''"
            );
            
            if ($update_result !== false) {
                //error_log("MxChat: Updated {$update_result} existing records to public access");
            }
        }
    }
}

/**
 *   Add enabled_bots column to intents table for multi-bot action filtering
 */
function mxchat_add_enabled_bots_column() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_intents';
    
    // Check if table exists first
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    if (!$table_exists) {
        //error_log("MxChat: Intents table does not exist, cannot add enabled_bots column");
        return;
    }
    
    // Check if column already exists
    $column_exists = $wpdb->get_results(
        $wpdb->prepare(
            "SHOW COLUMNS FROM {$table_name} LIKE %s",
            'enabled_bots'
        )
    );
    
    if (empty($column_exists)) {
        $alter_sql = "ALTER TABLE {$table_name} ADD COLUMN enabled_bots LONGTEXT DEFAULT NULL AFTER enabled";
        $result = $wpdb->query($alter_sql);
        
        if ($result === false) {
            //error_log("MxChat: Failed to add enabled_bots column. Error: " . $wpdb->last_error);
        } else {
            //error_log("MxChat: Successfully added enabled_bots column");
            
            // Set all existing actions to work with 'default' bot for backward compatibility
            $default_bots = json_encode(['default']);
            $update_result = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table_name} 
                     SET enabled_bots = %s 
                     WHERE enabled_bots IS NULL OR enabled_bots = ''",
                    $default_bots
                )
            );
            
            if ($update_result !== false) {
                //error_log("MxChat: Updated {$update_result} existing actions to work with default bot");
            }
        }
    }
}

/**
 * Create Pinecone role restrictions table with multi-bot support
 */
function mxchat_create_pinecone_roles_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'mxchat_pinecone_roles';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        vector_id varchar(255) NOT NULL,
        bot_id varchar(50) NOT NULL DEFAULT 'default',
        source_url text,
        role_restriction varchar(50) DEFAULT 'public',
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY vector_bot (vector_id, bot_id),
        KEY role_restriction (role_restriction),
        KEY bot_id (bot_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Add bot_id column to mxchat_pinecone_roles table for multi-bot support
 * This migration runs once to update existing installations
 */
function mxchat_migrate_pinecone_roles_add_bot_id() {
    global $wpdb;

    // Check if migration already ran
    $migration_version = get_option('mxchat_pinecone_roles_migration_version', '0');
    if (version_compare($migration_version, '2.5.2', '>=')) {
        return; // Already migrated
    }

    $table_name = $wpdb->prefix . 'mxchat_pinecone_roles';

    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
        return; // Table doesn't exist yet
    }

    // Check if bot_id column already exists
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'bot_id'");

    if (empty($column_exists)) {
        // Add bot_id column
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN bot_id VARCHAR(50) NOT NULL DEFAULT 'default' AFTER vector_id");

        // Update the unique key to include bot_id
        $wpdb->query("ALTER TABLE {$table_name} DROP INDEX vector_id");
        $wpdb->query("ALTER TABLE {$table_name} ADD UNIQUE KEY vector_bot (vector_id, bot_id)");

        // Add index for bot_id
        $wpdb->query("ALTER TABLE {$table_name} ADD KEY bot_id (bot_id)");

        //error_log('MxChat: Successfully added bot_id column to mxchat_pinecone_roles table');
    }

    // Mark migration as complete
    update_option('mxchat_pinecone_roles_migration_version', '2.5.2');
}

/**
 * 2.5.6: Add content_type column to mxchat_system_prompt_content table
 * Enables filtering knowledge base by content type (posts, pages, PDFs, etc.)
 */
function mxchat_migrate_add_content_type_column() {
    global $wpdb;

    // Check if migration already ran
    $migration_version = get_option('mxchat_content_type_migration_version', '0');
    if (version_compare($migration_version, '2.5.6', '>=')) {
        return;
    }

    $table_name = $wpdb->prefix . 'mxchat_system_prompt_content';

    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
        return;
    }

    // Check if content_type column already exists
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'content_type'");

    if (empty($column_exists)) {
        // Add content_type column with default value 'content' for backwards compatibility
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN content_type VARCHAR(50) DEFAULT 'content' AFTER role_restriction");

        // Add index for better query performance
        $wpdb->query("ALTER TABLE {$table_name} ADD KEY content_type (content_type)");

        //error_log('MxChat: Successfully added content_type column to mxchat_system_prompt_content table');
    }

    // Mark migration as complete
    update_option('mxchat_content_type_migration_version', '2.5.6');
}

/**
 * 3.2.4: Backfill the active embedding model option for installs that already
 * have KB content but no stamped model. The mismatch warning compares this
 * against the user's currently selected model — no per-row column needed.
 */
function mxchat_backfill_active_embedding_model() {
    global $wpdb;

    if (get_option('mxchat_active_embedding_model', '') !== '') {
        return;
    }

    $kb_table = $wpdb->prefix . 'mxchat_system_prompt_content';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$kb_table}'") !== $kb_table) {
        return;
    }

    $kb_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$kb_table}");
    if ($kb_count > 0) {
        $options = get_option('mxchat_options', array());
        $current_model = $options['embedding_model'] ?? 'text-embedding-ada-002';
        update_option('mxchat_active_embedding_model', $current_model, false);
    }
}

/**
 * 3.2.20: One-time backfill of the caee10 catalog usage-hint defaults
 * (plan 64c1ad). persist() writes usage_hint for EVERY shown tool on every
 * autosave — as '' when the box is untouched — and resolve_tool_setting()
 * seeds a catalog default_hint ONLY onto an ABSENT key. So any install that
 * saved the AI Tools screen before 3.2.20 holds '' everywhere and the shipped
 * defaults can never reach it. On an upgrade from < 3.2.20 an empty stored
 * hint cannot be a deliberate clear of a rendered default (those builds never
 * rendered one), so seeding is safe. From 3.2.20 on, empty means the owner
 * cleared a visible default and is respected — this never runs again (version
 * gate + its own marker, deliberately not caee10's flag).
 *
 * Never touched: entries with owner text, legacy bare-bool entries and
 * absent-key entries (both already resolve to the default at read time), and
 * tools whose catalog entry ships no default_hint.
 */
function mxchat_backfill_tool_hint_defaults() {
    if (get_option('mxchat_tool_hint_backfill_64c1ad') === '1') {
        return;
    }

    if (class_exists('MxChat_Tool_Registry')) {
        $map = get_option('mxchat_function_calling_tools', array());
        if (is_array($map) && !empty($map)) {
            $defaults = array();
            foreach (MxChat_Tool_Registry::core_tool_catalog() as $fn => $meta) {
                if (!empty($meta['default_hint'])) {
                    $defaults[$fn] = (string) $meta['default_hint'];
                }
            }

            $changed = false;
            foreach ($map as $fn => $entry) {
                if (!isset($defaults[$fn])) {
                    continue; // no catalog default — nothing to seed
                }
                if (!is_array($entry)) {
                    continue; // legacy bare bool: key absent, resolves to the default already
                }
                if (!array_key_exists('usage_hint', $entry)) {
                    continue; // absent key gets the default at read time — must stay absent
                }
                if (trim((string) $entry['usage_hint']) !== '') {
                    continue; // owner text — never touch
                }
                $map[$fn]['usage_hint'] = $defaults[$fn];
                $changed = true;
            }

            if ($changed) {
                update_option('mxchat_function_calling_tools', $map);
            }
        }
    }

    update_option('mxchat_tool_hint_backfill_64c1ad', '1', false);
}

/**
 * 2.5.2: Create queue processing tables for reliable background processing
 */
function mxchat_create_queue_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    // Main queue table
    $queue_table = $wpdb->prefix . 'mxchat_processing_queue';
    $sql_queue = "CREATE TABLE $queue_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        queue_id varchar(64) NOT NULL,
        item_type varchar(20) NOT NULL,
        item_data longtext NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        bot_id varchar(50) NOT NULL DEFAULT 'default',
        priority int(11) NOT NULL DEFAULT 0,
        attempts int(11) NOT NULL DEFAULT 0,
        max_attempts int(11) NOT NULL DEFAULT 3,
        error_message text DEFAULT NULL,
        created_at datetime NOT NULL,
        started_at datetime DEFAULT NULL,
        completed_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY queue_id (queue_id),
        KEY status (status),
        KEY item_type (item_type),
        KEY priority (priority)
    ) $charset_collate;";
    
    // Queue metadata table
    $meta_table = $wpdb->prefix . 'mxchat_queue_meta';
    $sql_meta = "CREATE TABLE $meta_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        queue_id varchar(64) NOT NULL,
        meta_key varchar(255) NOT NULL,
        meta_value longtext,
        PRIMARY KEY  (id),
        KEY queue_id (queue_id),
        KEY meta_key (meta_key)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_queue);
    dbDelta($sql_meta);
    
    //error_log("MxChat: Queue tables created/updated successfully");
}

/**
 * Create transcript translations table for persisting translations
 */
function mxchat_create_translations_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $table_name = $wpdb->prefix . 'mxchat_transcript_translations';
    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        session_id varchar(255) NOT NULL,
        language_code varchar(10) NOT NULL,
        translations longtext NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY session_lang (session_id, language_code),
        KEY session_id (session_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Create per-session satisfaction ratings table (v3.2.6)
 * Stores one 👍/👎 rating + optional feedback per chat session.
 */
function mxchat_create_session_ratings_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $table_name = $wpdb->prefix . 'mxchat_session_ratings';
    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        session_id varchar(255) NOT NULL,
        bot_id varchar(50) NOT NULL DEFAULT 'default',
        rating_value tinyint(1) NOT NULL,
        rating_feedback text DEFAULT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY session_id (session_id),
        KEY bot_id (bot_id),
        KEY created_at (created_at)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Create the OpenAI Vector Store file-mapping table (v3.2.20, plan 15b5c6).
 * One row per mirrored KB entry: which store, which bot, which OpenAI file id,
 * and a hash of the uploaded body for change detection. Vector store files are
 * not patchable in place — without this mapping an update cannot find its
 * predecessor, and the store silently accumulates stale duplicates.
 * status: 'live' (serving) or 'pending_delete' (condemned, swept later).
 */
function mxchat_create_vectorstore_files_table() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $table_name = $wpdb->prefix . 'mxchat_vectorstore_files';
    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        store_id varchar(64) NOT NULL,
        bot_id varchar(64) NOT NULL DEFAULT 'default',
        entry_key char(32) NOT NULL,
        source_url text,
        file_id varchar(64) NOT NULL,
        content_hash char(32) NOT NULL DEFAULT '',
        status varchar(20) NOT NULL DEFAULT 'live',
        last_error text,
        updated_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY store_entry (store_id, bot_id, entry_key, status),
        KEY status (status)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * 2.5.2: Fix URL column size to support long URLs (especially with UTF-8 encoding)
 * This fixes "url, source_url. The supplied values may be too long" errors
 */
function mxchat_fix_url_column_size() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_system_prompt_content';
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    if (!$table_exists) {
        return;
    }
    
    // Change url and source_url from VARCHAR to TEXT to handle long URLs
    // This is especially important for URLs with UTF-8 encoded characters (Hebrew, Arabic, etc.)
    $wpdb->query("ALTER TABLE {$table_name} MODIFY COLUMN url TEXT");
    $wpdb->query("ALTER TABLE {$table_name} MODIFY COLUMN source_url TEXT");
    
    //error_log("MxChat: Successfully updated url and source_url columns to TEXT type for long URL support");
}

/**
 * Migrate deprecated AI models to their replacements
 * Version 2.5.1: Migrate Claude 3.5 Sonnet (deprecated) to Claude 3.7 Sonnet
 * Version 3.1.2: Convert chat transcripts table to utf8mb4 for emoji support
 * Without utf8mb4, any bot response containing emojis silently fails to insert.
 */
function mxchat_migrate_transcripts_charset() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_chat_transcripts';
    $wpdb->query("ALTER TABLE $table_name CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

/**
 * Version 3.0.55: Migrate GPT-4 series models (deprecated 2026-02-17) to GPT-5 series
 */
function mxchat_migrate_deprecated_models() {
    $options = get_option('mxchat_options', array());
    $migrated = false;
    $migration_message = '';

    if (!isset($options['model'])) {
        return;
    }

    // The retired-id → replacement mapping lives in ONE place:
    // MxChat_Model_Catalog::retired_model_map() (plan 202df5). This function
    // and every add-on that stores model ids consume that map — do not add a
    // deprecation list here again.
    if (!class_exists('MxChat_Model_Catalog') || !method_exists('MxChat_Model_Catalog', 'retired_model_map')) {
        return; // catalog not loaded (defensive) — the next admin load retries
    }
    $map = MxChat_Model_Catalog::retired_model_map();

    $current_model = $options['model'];
    if (isset($map[$current_model])) {
        $entry = $map[$current_model];
        $options['model'] = $entry['to'];
        $migrated = true;
        $migration_message = sprintf(
            'Your chatbot model has been automatically updated from %s to %s %s.',
            $current_model,
            $entry['label'],
            $entry['reason']
        );
    }

    // The content generator has its own model option — the same map applies.
    // (Pre-202df5 this branch covered only the two gpt-5.x-chat-latest aliases;
    // it now covers every retired id, so e.g. a content model stranded on a
    // retired Claude id is rescued the same way the chat model is.)
    if (isset($options['content_model']) && isset($map[$options['content_model']])) {
        $old_content_model = $options['content_model'];
        $entry = $map[$old_content_model];
        $options['content_model'] = $entry['to'];
        $migrated = true;
        $migration_message = trim($migration_message . ' ' . sprintf(
            'Your content generation model has also been automatically updated from %s to %s %s.',
            $old_content_model,
            $entry['label'],
            $entry['reason']
        ));
    }

    if ($migrated) {
        update_option('mxchat_options', $options);
        update_option('mxchat_model_migrated_notice', true);
        update_option('mxchat_model_migration_message', $migration_message);
    }
}

/**
 * Show admin notice after model migration
 */
function mxchat_show_migration_notice() {
    if (get_option('mxchat_model_migrated_notice')) {
        $migration_message = get_option('mxchat_model_migration_message', __('Your chatbot model has been automatically updated due to a model deprecation.', 'mxchat'));
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <strong><?php esc_html_e('MxChat Model Updated', 'mxchat'); ?></strong><br>
                <?php echo esc_html($migration_message); ?>
            </p>
        </div>
        <?php
        delete_option('mxchat_model_migrated_notice');
        delete_option('mxchat_model_migration_message');
    }
}

/**
 * One-time recommendation on EXISTING installs (stamp 'legacy') that the new
 * "Strip unapproved links" guard exists and is worth turning on (plan 58f8b4).
 * Fresh 3.2.20+ installs default it on and never see this. Shown only on
 * MxChat admin pages, gone for good once dismissed or once the site saves an
 * explicit value for the toggle either way.
 */
function mxchat_show_strip_links_notice() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if (strpos($page, 'mxchat') !== 0) {
        return;
    }
    if (get_option('mxchat_strip_links_notice_dismissed', '') === '1') {
        return;
    }
    if (get_option('mxchat_installed_at_version', '') !== 'legacy') {
        return;
    }
    $opts = get_option('mxchat_options', array());
    if (is_array($opts) && isset($opts['strip_unapproved_links_toggle'])) {
        return; // The site already made its choice — stop recommending.
    }
    $dismiss_url = wp_nonce_url(
        admin_url('admin-post.php?action=mxchat_dismiss_strip_links_notice'),
        'mxchat_dismiss_strip_links_notice'
    );
    ?>
    <div class="notice notice-info">
        <p>
            <strong><?php esc_html_e('MxChat: new link protection available', 'mxchat'); ?></strong><br>
            <?php esc_html_e('The new "Strip Unapproved Links" setting removes links the AI invents from its answers even when Citation Links is off — links to real pages on your site and links your integrations return are always kept. It is off on existing sites so nothing changes without you; we recommend turning it on under MxChat → Settings → Chatbot Behavior.', 'mxchat'); ?>
            <a href="<?php echo esc_url($dismiss_url); ?>"><?php esc_html_e('Dismiss', 'mxchat'); ?></a>
        </p>
    </div>
    <?php
}
add_action('admin_notices', 'mxchat_show_strip_links_notice');

/** Dismiss handler for the strip-links recommendation notice (plan 58f8b4). */
function mxchat_dismiss_strip_links_notice() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'mxchat'), '', array('response' => 403));
    }
    check_admin_referer('mxchat_dismiss_strip_links_notice');
    update_option('mxchat_strip_links_notice_dismissed', '1', false);
    $referer = wp_get_referer();
    wp_safe_redirect($referer ? $referer : admin_url('admin.php?page=mxchat-max'));
    exit;
}
add_action('admin_post_mxchat_dismiss_strip_links_notice', 'mxchat_dismiss_strip_links_notice');

/**
 * Persistent admin notice when the provider rejected the configured model
 * (model_not_found / no access). Set by mxchat_friendly_chat_error() in the
 * integrator whenever a chat request fails on a model-access error — including
 * requests from anonymous visitors, which is the case that otherwise stays
 * invisible to the site owner for weeks (plan e46b8f).
 *
 * Deleted after render so it re-arms on the next failed chat: the notice keeps
 * reappearing until the model is fixed, then stops on its own.
 */
function mxchat_show_model_access_notice() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $notice = get_option('mxchat_model_access_notice');
    if (!is_array($notice) || empty($notice['model'])) {
        return;
    }
    $settings_url = admin_url('admin.php?page=mxchat-max');
    ?>
    <div class="notice notice-error is-dismissible">
        <p>
            <strong><?php esc_html_e('MxChat: your AI model is being rejected by the provider', 'mxchat'); ?></strong><br>
            <?php
            printf(
                /* translators: 1: model id, 2: provider name */
                esc_html__('Chat requests using the model "%1$s" are failing because %2$s reports it as unavailable on your API key (it may have been retired). Visitors may be seeing errors instead of replies.', 'mxchat'),
                esc_html($notice['model']),
                esc_html(!empty($notice['provider']) ? $notice['provider'] : __('the AI provider', 'mxchat'))
            );
            ?>
            <a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Choose a different model in MxChat Settings', 'mxchat'); ?></a>
        </p>
    </div>
    <?php
    delete_option('mxchat_model_access_notice');
}

function mxchat_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    //error_log("MxChat: Running activation function");

    // Create chat transcripts table with improved function
    mxchat_create_chat_transcripts_table();

    // Per-session state table (b64b77). Activation can run before
    // plugins_loaded has included the class files, so require it directly.
    mxchat_create_sessions_table();

    // System Prompt Content Table - UPDATED: Use TEXT for url and source_url columns
    $system_prompt_table = $wpdb->prefix . 'mxchat_system_prompt_content';
    $sql_system_prompt = "CREATE TABLE $system_prompt_table (
        id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
        url TEXT NOT NULL,
        article_content LONGTEXT NOT NULL,
        embedding_vector LONGTEXT,
        source_url TEXT DEFAULT NULL,
        role_restriction VARCHAR(50) DEFAULT 'public',
        content_type VARCHAR(50) DEFAULT 'content',
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id),
        KEY content_type (content_type)
    ) $charset_collate;";

    // Intents Table - NOW INCLUDES enabled_bots column from the start
    $intents_table = $wpdb->prefix . 'mxchat_intents';
    $sql_intents_table = "CREATE TABLE $intents_table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        intent_label VARCHAR(255) NOT NULL,
        phrases TEXT NOT NULL,
        embedding_vector LONGTEXT NOT NULL,
        callback_function VARCHAR(255) NOT NULL,
        similarity_threshold FLOAT DEFAULT 0.85,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        enabled_bots LONGTEXT DEFAULT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    // Individual Intent Phrases Table - each phrase gets its own embedding vector
    $intent_phrases_table = $wpdb->prefix . 'mxchat_intent_phrases';
    $sql_intent_phrases_table = "CREATE TABLE $intent_phrases_table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        intent_id BIGINT(20) UNSIGNED NOT NULL,
        phrase TEXT NOT NULL,
        embedding_vector LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id),
        KEY intent_id (intent_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Create other tables
    dbDelta($sql_system_prompt);
    dbDelta($sql_intents_table);
    dbDelta($sql_intent_phrases_table);

    // Create URL click tracking table
    mxchat_create_url_clicks_table();
    
    // Create Pinecone roles table
    mxchat_create_pinecone_roles_table();
    
    // NEW 2.5.2: Create queue processing tables
    mxchat_create_queue_tables();

    // Create transcript translations table
    mxchat_create_translations_table();

    // Create per-session satisfaction ratings table (v3.2.6)
    mxchat_create_session_ratings_table();

    // Create Vector Store file-mapping table (v3.2.20, plan 15b5c6)
    mxchat_create_vectorstore_files_table();

    // Ensure additional columns in system prompt table
    $existing_system_columns = $wpdb->get_results("SHOW COLUMNS FROM $system_prompt_table");
    if (!empty($existing_system_columns)) {
        $existing_system_column_names = array_column($existing_system_columns, 'Field');
        
        if (!in_array('embedding_vector', $existing_system_column_names)) {
            $wpdb->query("ALTER TABLE $system_prompt_table ADD COLUMN embedding_vector LONGTEXT");
        }
        if (!in_array('source_url', $existing_system_column_names)) {
            $wpdb->query("ALTER TABLE $system_prompt_table ADD COLUMN source_url TEXT DEFAULT NULL");
        }
        if (!in_array('role_restriction', $existing_system_column_names)) {
            $wpdb->query("ALTER TABLE $system_prompt_table ADD COLUMN role_restriction VARCHAR(50) DEFAULT 'public' AFTER source_url");
        }
    }

    // Set default thresholds for existing intents
    $wpdb->query("UPDATE {$intents_table} SET similarity_threshold = 0.85 WHERE similarity_threshold IS NULL");
    
    // Ensure enabled column exists in intents table
    $existing_intent_columns = $wpdb->get_results("SHOW COLUMNS FROM $intents_table");
    if (!empty($existing_intent_columns)) {
        $existing_intent_column_names = array_column($existing_intent_columns, 'Field');
        
        if (!in_array('enabled', $existing_intent_column_names)) {
            $wpdb->query("ALTER TABLE $intents_table ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1");
        }
        
        //   Ensure enabled_bots column exists for existing installations
        if (!in_array('enabled_bots', $existing_intent_column_names)) {
            $wpdb->query("ALTER TABLE $intents_table ADD COLUMN enabled_bots LONGTEXT DEFAULT NULL AFTER enabled");
            
            // Set existing actions to work with default bot
            $default_bots = json_encode(['default']);
            $wpdb->query($wpdb->prepare(
                "UPDATE {$intents_table} SET enabled_bots = %s WHERE enabled_bots IS NULL",
                $default_bots
            ));
        }
    }

    // Run migration for existing installations
    mxchat_migrate_pinecone_roles_add_bot_id();

    // 3.2.4: Backfill active embedding model option (replaces 3.2.3 column-based tracking)
    mxchat_backfill_active_embedding_model();

    // Setup cron jobs
    mxchat_setup_cron_jobs();

    // Update version (stable base version — never the dev time()-suffixed one,
    // or the check_for_update comparison would churn every request)
    update_option('mxchat_plugin_version', MXCHAT_BASE_VERSION);

    //error_log("MxChat: Activation function completed");
}

/**
 * Setup cron jobs on plugin activation
 */
function mxchat_setup_cron_jobs() {
    // Clear any existing cron jobs first
    wp_clear_scheduled_hook('mxchat_reset_rate_limits');
    
    // Check if WordPress cron is disabled
    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
        // Set flag to use fallback system
        update_option('mxchat_use_fallback_rate_limits', true);
        update_option('mxchat_next_rate_limit_check', time() + 3600);
        // Deliberately NO early return (plan-bc08a6): transcript cleanup below
        // must still be scheduled. DISABLE_WP_CRON only changes HOW cron events
        // execute (a server-side runner hitting wp-cron.php instead of loopback
        // spawns) — scheduling still just writes the cron option. The old early
        // return here meant a deactivate/reactivate cycle on a DISABLE_WP_CRON
        // site permanently lost the transcript cleanup event while the retention
        // setting still claimed to be active.
    } else {
        // Schedule the rate limit reset cron job
        $result = wp_schedule_event(time() + 300, 'hourly', 'mxchat_reset_rate_limits');

        if ($result === false) {
            // Fallback if scheduling fails
            update_option('mxchat_use_fallback_rate_limits', true);
            update_option('mxchat_next_rate_limit_check', time() + 3600);
        } else {
            // Clear fallback flags if cron scheduling succeeded
            delete_option('mxchat_use_fallback_rate_limits');
        }
    }

    // Schedule transcript cleanup if configured (bucket dropdown OR custom retention-days > 0)
    $transcript_options = get_option('mxchat_transcripts_options', array());
    $cleanup_interval = isset($transcript_options['mxchat_auto_delete_transcripts']) ? $transcript_options['mxchat_auto_delete_transcripts'] : 'never';
    $custom_retention = isset($transcript_options['mxchat_retention_days']) ? (int) $transcript_options['mxchat_retention_days'] : 0;

    if ($cleanup_interval !== 'never' || $custom_retention > 0) {
        // Check if not already scheduled
        if (!wp_next_scheduled('mxchat_cleanup_old_transcripts')) {
            // Schedule to run daily at 3 AM
            $next_run = strtotime('tomorrow 3:00 AM');
            wp_schedule_event($next_run, 'daily', 'mxchat_cleanup_old_transcripts');
        }
    }
}

/**
 * Clean up on plugin deactivation
 */
function mxchat_deactivate() {
    // Clear scheduled cron jobs
    wp_clear_scheduled_hook('mxchat_reset_rate_limits');
    wp_clear_scheduled_hook('mxchat_cleanup_old_transcripts');
    wp_clear_scheduled_hook('mxchat_send_delayed_transcript');
    wp_clear_scheduled_hook('mxchat_model_liveness_check');
    
    // Clear fallback options
    delete_option('mxchat_use_fallback_rate_limits');
    delete_option('mxchat_next_rate_limit_check');
    delete_option('mxchat_fallback_check_interval');
    
    // NOTE: We do NOT delete queue tables on deactivation
    // This preserves data if user accidentally deactivates the plugin
}

/**
 * Check if fallback rate limit cleanup is needed
 */
function mxchat_check_fallback_rate_limits() {
    $use_fallback = get_option('mxchat_use_fallback_rate_limits', false);
    
    if (!$use_fallback) {
        return;
    }
    
    $next_check = get_option('mxchat_next_rate_limit_check', 0);

    if (time() >= $next_check) {
        // Reuse the bootstrap's integrator — mxchat_init() creates the global on
        // plugins_loaded (before this init-priority-5 callback), so it's always set
        // here. Constructing a second MxChat_Integrator just to call one method
        // re-registers every hook the plugin has (ajax pairs, wp_footer loader,
        // rest_api_init, admin_init guard) on a duplicate instance for the rest of
        // the request. Defensive construction only if the global is somehow unset.
        // NOTE: MxChat_Integrator::check_fallback_rate_limits() is a second
        // implementation of this same check — if either changes, change both.
        global $mxchat_integrator;
        $integrator = ($mxchat_integrator instanceof MxChat_Integrator)
            ? $mxchat_integrator
            : (class_exists('MxChat_Integrator') ? new MxChat_Integrator() : null);
        if ($integrator && method_exists($integrator, 'mxchat_reset_rate_limits')) {
            $integrator->mxchat_reset_rate_limits();
            update_option('mxchat_next_rate_limit_check', time() + 3600);
        }
    }
}

/**
 * Robust update checking with role restriction migration, model deprecation, and queue tables
 * CRITICAL: This runs on EVERY page load to ensure tables exist
 */
function mxchat_check_for_update() {
    global $wpdb;
    
    try {
        $current_version = get_option('mxchat_plugin_version', '0.0.0');
        $plugin_version = MXCHAT_BASE_VERSION;

        // Always ensure critical tables exist (even if version matches)
        // This handles manual table deletion or fresh installs
        $chat_table = $wpdb->prefix . 'mxchat_chat_transcripts';
        $queue_table = $wpdb->prefix . 'mxchat_processing_queue';
        
        $sessions_table = $wpdb->prefix . 'mxchat_sessions';

        $chat_exists = $wpdb->get_var("SHOW TABLES LIKE '$chat_table'") === $chat_table;
        $queue_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'") === $queue_table;
        $sessions_exists = get_option('mxchat_session_store_ready') === '1'
            || $wpdb->get_var("SHOW TABLES LIKE '$sessions_table'") === $sessions_table;

        if (!$chat_exists || !$queue_exists || !$sessions_exists) {
            //error_log("MxChat: Critical tables missing, running activation");
            mxchat_activate();
        }

        // Version-specific migrations
        if ($current_version !== $plugin_version) {
            //error_log("MxChat: Version change detected: $current_version -> $plugin_version");

            // Run live agent update BEFORE updating the stored version
            mxchat_handle_live_agent_update();

            // Run theme migration notice for 3.0.1 (AI theme CSS structure changes)
            mxchat_handle_theme_migration_notice();
            
            // Run role restriction migration for 2.4.1
            if (version_compare($current_version, '2.4.1', '<')) {
                mxchat_add_role_restriction_column();
            }
            
            // Run enabled_bots column migration for 2.4.4
            if (version_compare($current_version, '2.4.4', '<')) {
                mxchat_add_enabled_bots_column();
            }
            
            // Run model migration for 2.5.1 (Claude deprecation)
            if (version_compare($current_version, '2.5.1', '<')) {
                mxchat_migrate_deprecated_models();
            }
            
            // 2.5.2: Ensure queue tables exist and fix URL column sizes for all users upgrading to 2.5.2
            if (version_compare($current_version, '2.5.2', '<')) {
                mxchat_create_queue_tables();
                mxchat_fix_url_column_size(); // NEW: Fix URL column size for long URLs
                //error_log("MxChat: Queue tables created and URL columns updated for upgrade to 2.5.2");
            }

            // 2.6.0: Ensure rag_context column exists for retrieved documents feature
            if (version_compare($current_version, '2.6.0', '<')) {
                $chat_table = $wpdb->prefix . 'mxchat_chat_transcripts';
                mxchat_ensure_all_columns($chat_table);
                //error_log("MxChat: rag_context column migration for 2.6.0");
            }

            // 3.0.5: Migrate deprecated Gemini embedding model
            if (version_compare($current_version, '3.0.5', '<')) {
                mxchat_migrate_gemini_embedding_model();
            }

            // 3.0.6: Migrate deprecated OpenAI and Claude models
            if (version_compare($current_version, '3.0.6', '<')) {
                mxchat_migrate_deprecated_models();
            }

            // 3.1.2: Convert chat transcripts table to utf8mb4 for emoji support
            if (version_compare($current_version, '3.1.2', '<')) {
                mxchat_migrate_transcripts_charset();
            }

            // 3.1.7: Clean up stale shared session email/name entries
            if (version_compare($current_version, '3.1.7', '<')) {
                delete_option('mxchat_email_null');
                delete_option('mxchat_name_null');
            }

            // 3.2.4: Backfill active embedding model option for the warning UI
            // (replaces the per-row column tracking from 3.2.3, which was reverted)
            if (version_compare($current_version, '3.2.4', '<')) {
                mxchat_backfill_active_embedding_model();
            }

            // 3.2.15: Migrate retired DeepSeek ids (deepseek-chat / deepseek-reasoner
            // were shut off at the vendor on 2026-07-24). The function is idempotent —
            // it only rewrites models on its deprecation lists.
            if (version_compare($current_version, '3.2.15', '<')) {
                mxchat_migrate_deprecated_models();
            }

            // 3.2.16: Migrate OpenAI ids retiring 2026-08-10 (gpt-5.1-chat-latest /
            // gpt-5.3-chat-latest → gpt-5.6-sol per OpenAI's deprecations page).
            // Idempotent — only rewrites models on the deprecation lists (e46b8f).
            if (version_compare($current_version, '3.2.16', '<')) {
                mxchat_migrate_deprecated_models();
            }

            // 3.2.17: Credential options must not autoload (af2400) — the two
            // Pinecone-secret-holding rows were in alloptions, i.e. read into
            // memory on every request including anonymous page views. Idempotent.
            // Also carry the import modal's remembered ACF→PDF checkbox state
            // into the new install-level option (11720c).
            if (version_compare($current_version, '3.2.17', '<')) {
                mxchat_fix_credential_option_autoload();
                mxchat_migrate_acf_pdf_extraction_option();
            }

            // 3.2.19: Claude Opus 4.1 retired Aug 5, 2026 — auto-move stranded
            // sites (and the older tiers on the migration's lists) per the
            // changelog's promise. Idempotent — only rewrites models on the
            // deprecation lists. Without a gate at this release's version,
            // nothing calls the migration for 3.2.18 upgraders (plan a5a598;
            // the gate value must equal the version this block ships in).
            if (version_compare($current_version, '3.2.19', '<')) {
                mxchat_migrate_deprecated_models();
            }

            // 3.2.20: Seed the caee10 usage-hint defaults onto tool entries a
            // pre-3.2.20 autosave stamped with '' (plan 64c1ad). The gate value
            // equals the version this block ships in (a5a598 rule); the
            // function carries its own one-time marker on top.
            // Also re-run the deprecated-models migration: 202df5 widened it to
            // rescue a content_model stranded on ANY retired id (previously
            // only the two gpt-5.x-chat-latest aliases). Idempotent — only
            // rewrites models on the catalog's retired_model_map().
            if (version_compare($current_version, '3.2.20', '<')) {
                mxchat_backfill_tool_hint_defaults();
                mxchat_migrate_deprecated_models();
            }

            // Run full activation to ensure everything is up to date
            mxchat_activate();
            
            // Run migration functions
            mxchat_migrate_live_agent_status();

            // (The 2.1.8 orphaned-history reconciliation sweep is gone —
            // 3.2.19's mxchat_history_backlog_* drain supersedes it, and it
            // self-arms without a version gate: plan 839c4c.)

            // Update version LAST
            update_option('mxchat_plugin_version', $plugin_version);
            
            //error_log("MxChat: Updated from version $current_version to $plugin_version");
        }
        
    } catch (Exception $e) {
        //error_log('MxChat update error: ' . $e->getMessage());
        // Don't update version if there was an error
    }
}

/**
 * Credential options must never enter the autoloaded alloptions set.
 * mxchat_prompts_options and mxchat_pinecone_addon_options can hold the
 * Pinecone API secret; mxchat_options already stores its keys with autoload
 * off and these two must match it. The filter covers every future
 * add_option()/update_option() that creates the row — Settings API saves
 * through options.php and WP-CLI included — on WP 6.6+; older cores are
 * covered by the explicit autoload arguments at the plugin's own write
 * sites plus the one-time migration below.
 */
add_filter('wp_default_autoload_value', 'mxchat_credential_option_autoload_value', 10, 2);
function mxchat_credential_option_autoload_value($autoload, $option) {
    if (in_array($option, array('mxchat_prompts_options', 'mxchat_pinecone_addon_options'), true)) {
        return false;
    }
    return $autoload;
}

/**
 * One-time upgrade migration: flip the autoload flag on credential option
 * rows that existing installs are already carrying autoloaded. Includes
 * mxchat_adv_api_token (Advanced Content bearer token) — harmless no-op
 * when that add-on is not installed, since missing rows simply don't match.
 */
function mxchat_fix_credential_option_autoload() {
    $keys = array('mxchat_prompts_options', 'mxchat_pinecone_addon_options', 'mxchat_adv_api_token');
    if (function_exists('wp_set_option_autoload_values')) {
        wp_set_option_autoload_values(array_fill_keys($keys, false));
        return;
    }
    // Pre-WP-6.4 fallback: direct flip + cache invalidation.
    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($keys), '%s'));
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name IN ($placeholders)", $keys));
    wp_cache_delete('alloptions', 'options');
    foreach ($keys as $key) {
        wp_cache_delete($key, 'options');
    }
}

/**
 * One-time carry of the import modal's remembered ACF→PDF checkbox state
 * (mxchat_options['acf_pdf_extract_default'], written per-import until 3.2.16)
 * into the new install-level option mxchat_acf_pdf_extraction (plan 11720c).
 * Fresh installs and installs that never touched the checkbox default OFF,
 * matching the setting's own "recommended only if…" guidance.
 */
function mxchat_migrate_acf_pdf_extraction_option() {
    if (get_option('mxchat_acf_pdf_extraction', null) !== null) {
        return; // already set — never overwrite an owner's choice
    }
    $mxchat_options = get_option('mxchat_options', array());
    if (is_array($mxchat_options) && array_key_exists('acf_pdf_extract_default', $mxchat_options)) {
        update_option('mxchat_acf_pdf_extraction', !empty($mxchat_options['acf_pdf_extract_default']) ? '1' : '0', false);
        unset($mxchat_options['acf_pdf_extract_default']);
        update_option('mxchat_options', $mxchat_options);
    }
}

/**
 * One-time migration of mxchat_acf_excluded_fields from field NAMES to field
 * KEYS (plan 30e81f). Names are not unique across ACF groups, so two fields
 * named the same in different groups shared one toggle, one saved state, and
 * one exclusion — and the save-on-exit beacon could revert a save through the
 * twin. Keys are unique; everything now runs on them.
 *
 * MIGRATION DIRECTION IS DELIBERATE: one stored name can match several keys —
 * exclude EVERY one of them. The UI describes these as "sensitive or
 * irrelevant fields"; an under-migration would silently start feeding a
 * previously-excluded sensitive field into embeddings sent to a third-party
 * provider. Over-excluding is visible in the UI and costs some retrieval
 * quality; under-excluding is a silent privacy regression.
 *
 * Self-arming on acf/init (NOT the version-gated upgrade block): resolving
 * names needs ACF fully booted with local JSON/PHP groups registered, and if
 * ACF is deactivated at upgrade time the migration simply waits for the next
 * load with ACF active. Until it runs, stored names keep working — every
 * exclusion read site honors legacy name entries alongside keys. A name that
 * matches no current key is KEPT (its group may be temporarily inactive), and
 * the per-field include path lazily converts it if it ever resolves again.
 */
function mxchat_migrate_acf_exclusions_to_keys() {
    if (get_option('mxchat_acf_exclusions_migrated', '') === '1') {
        return;
    }
    $stored = get_option('mxchat_acf_excluded_fields', array());
    if (!is_array($stored) || empty($stored)) {
        update_option('mxchat_acf_exclusions_migrated', '1', false);
        return;
    }
    if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
        return; // acf/init fired without the API? Bail; retry next load.
    }

    // Map every current top-level field: name => [keys], and the set of keys.
    $name_to_keys = array();
    $current_keys = array();
    foreach (acf_get_field_groups() as $group) {
        $group_fields = acf_get_fields($group['key']);
        if (empty($group_fields)) {
            continue;
        }
        foreach ($group_fields as $field) {
            if (empty($field['key']) || !isset($field['name'])) {
                continue;
            }
            $current_keys[$field['key']] = true;
            $name_to_keys[$field['name']][] = $field['key'];
        }
    }

    $migrated = array();
    $converted = 0;
    foreach ($stored as $entry) {
        if (!is_string($entry) || $entry === '') {
            continue;
        }
        if (isset($current_keys[$entry])) {
            $migrated[] = $entry; // already a live key
        } elseif (isset($name_to_keys[$entry])) {
            foreach ($name_to_keys[$entry] as $key) {
                $migrated[] = $key; // every key wearing this name — fail toward more exclusion
            }
            $converted++;
        } else {
            $migrated[] = $entry; // unresolved — keep; still honored by name everywhere
        }
    }
    $migrated = array_values(array_unique($migrated));

    update_option('mxchat_acf_excluded_fields', $migrated);
    update_option('mxchat_acf_exclusions_migrated', '1', false);
    if ($converted > 0) {
        update_option('mxchat_acf_exclusions_migrated_notice', '1', false);
    }
}
add_action('acf/init', 'mxchat_migrate_acf_exclusions_to_keys', 20);

/**
 * One-time notice after the ACF exclusion migration actually converted
 * name entries — the settings are worth a review, especially where one name
 * fanned out to several fields (every match is now excluded, on purpose).
 */
function mxchat_show_acf_exclusions_migrated_notice() {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (get_option('mxchat_acf_exclusions_migrated_notice', '') !== '1') {
        return;
    }
    ?>
    <div class="notice notice-info is-dismissible">
        <p>
            <strong><?php esc_html_e('MxChat: ACF field exclusions updated', 'mxchat'); ?></strong><br>
            <?php esc_html_e('Your ACF field exclusion settings were migrated to identify fields precisely, so same-named fields in different groups no longer share one toggle. Where a saved exclusion matched several fields, all of them are now excluded — please review the toggles under MxChat → Knowledge → ACF Field Settings.', 'mxchat'); ?>
        </p>
    </div>
    <?php
    delete_option('mxchat_acf_exclusions_migrated_notice');
}
add_action('admin_notices', 'mxchat_show_acf_exclusions_migrated_notice');

/**
 * Ensure tables exist on every admin load for fresh installations
 * This is a safety net for cases where activation hook doesn't fire
 */
function mxchat_ensure_tables_exist() {
    global $wpdb;
    
    // Only run for admin users to avoid performance impact
    if (!current_user_can('administrator')) {
        return;
    }
    
    // Check if we've already verified tables in this session
    static $tables_checked = false;
    if ($tables_checked) {
        return;
    }
    $tables_checked = true;
    
    $table_name = $wpdb->prefix . 'mxchat_chat_transcripts';
    $queue_table = $wpdb->prefix . 'mxchat_processing_queue';
    
    $sessions_table = $wpdb->prefix . 'mxchat_sessions';

    $chat_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    $queue_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'") === $queue_table;
    $sessions_exists = get_option('mxchat_session_store_ready') !== false
        || $wpdb->get_var("SHOW TABLES LIKE '$sessions_table'") === $sessions_table;

    if (!$chat_exists || !$queue_exists || !$sessions_exists) {
        //error_log("MxChat: Tables missing on admin load, running activation");
        mxchat_activate();
    }
}

/**
 * One-shot cleanup of legacy mxchat_history_<sid> option rows (plan 839c4c).
 *
 * 3.2.19 deduplicated per-session chat history into the transcripts table
 * (which already held a superset of every option copy measured), so nothing
 * writes these options any more — but an upgraded install still carries one
 * per session, at up to 64 KB a row (462 rows measured on one production
 * install). This drain replaces the old mxchat_cleanup_orphaned_chat_history()
 * reconciliation sweep, which existed only because there were two copies to
 * reconcile.
 *
 * b64b77 pattern throughout: an option_id bookmark that only moves forward
 * (a crash mid-batch re-processes at most one batch), delete_option() per row
 * so the object + notoptions caches stay coherent, batches drained off
 * non-AJAX admin_init plus the session-store maintenance cron. Once the
 * backlog is gone the state marks done and every later call is one cached
 * option read.
 */
function mxchat_history_backlog_state() {
    // The state option name MUST NOT start with 'mxchat_history_' — the drain
    // deletes everything matching that prefix, and a state option inside the
    // pattern gets eaten by its own drain (caught by the 839c4c rig: batches
    // ran 2,2,2,1 instead of 2,2,1 because the bookmark row was being deleted
    // and re-created every pass).
    $state = get_option('mxchat_legacy_history_cleanup', array());
    if (!is_array($state)) {
        $state = array();
    }

    return wp_parse_args($state, array(
        'done'           => false,
        'last_option_id' => 0,
        'deleted'        => 0,
    ));
}

/**
 * Delete one batch of legacy history options.
 *
 * @param int $batch Rows per pass — capped small; these can be 64 KB rows.
 * @return int Option rows deleted in this pass.
 */
function mxchat_history_backlog_batch($batch = 200) {
    global $wpdb;

    $state = mxchat_history_backlog_state();
    if (!empty($state['done'])) {
        return 0;
    }

    $batch = max(1, (int) $batch);

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_id, option_name FROM {$wpdb->options}
             WHERE option_id > %d AND option_name LIKE %s
             ORDER BY option_id ASC LIMIT %d",
            (int) $state['last_option_id'],
            $wpdb->esc_like('mxchat_history_') . '%',
            $batch
        ),
        ARRAY_A
    );

    if (empty($rows)) {
        $state['done'] = true;
        update_option('mxchat_legacy_history_cleanup', $state, 'no');
        return 0;
    }

    foreach ($rows as $row) {
        $state['last_option_id'] = max((int) $state['last_option_id'], (int) $row['option_id']);
        delete_option($row['option_name']);
        $state['deleted'] = (int) $state['deleted'] + 1;
    }

    if (count($rows) < $batch) {
        $state['done'] = true;
    }

    update_option('mxchat_legacy_history_cleanup', $state, 'no');

    return count($rows);
}

/** One batch per admin page load until drained. */
function mxchat_history_backlog_drain() {
    mxchat_history_backlog_batch();
}

/** Cron leg: drain faster, same cap per batch. */
function mxchat_history_backlog_drain_cron() {
    for ($i = 0; $i < 10; $i++) {
        if (mxchat_history_backlog_batch() === 0) {
            break;
        }
    }
}

// wp_doing_ajax() guard is load-bearing, not defensive (b64b77's shipped-and-
// caught defect): admin-ajax.php fires admin_init too, and the chat widget's
// message endpoint is an admin-ajax action — without the guard an anonymous
// visitor would pay for a delete batch inside their own chat request.
if (!wp_doing_ajax()) {
    add_action('admin_init', 'mxchat_history_backlog_drain', 21);
}
// Belt for installs whose admin is rarely visited: ride the session store's
// existing daily maintenance event rather than scheduling another.
add_action('mxchat_session_store_maintenance', 'mxchat_history_backlog_drain_cron');

function mxchat_migrate_live_agent_status() {
    $options = get_option('mxchat_options', []);

    // Check if live_agent_status exists
    if (isset($options['live_agent_status'])) {
        $current_status = $options['live_agent_status'];
        $needs_update = false;

        // Convert to new format if needed
        if ($current_status === 'online') {
            $options['live_agent_status'] = 'on';
            $needs_update = true;
        } else if ($current_status === 'offline') {
            $options['live_agent_status'] = 'off';
            $needs_update = true;
        } else if (!in_array($current_status, ['on', 'off'])) {
            // Default to off for any unexpected values
            $options['live_agent_status'] = 'off';
            $needs_update = true;
        }

        // Only update if needed
        if ($needs_update) {
            update_option('mxchat_options', $options);
        }
    } else {
        // If status doesn't exist, set default to off
        $options['live_agent_status'] = 'off';
        update_option('mxchat_options', $options);
    }
}

function mxchat_handle_live_agent_update() {
    // Get the CURRENT stored version (before it gets updated)
    $current_version = get_option('mxchat_plugin_version', '0.0.0');
    $new_version = '2.2.2';

    // Only run this once for the update to 2.2.2
    $update_handled = get_option('mxchat_live_agent_update_2_2_2_handled', false);

    // Check if we're upgrading TO 2.2.2 and haven't handled this yet
    if (version_compare($current_version, $new_version, '<') && !$update_handled) {
        $options = get_option('mxchat_options', array());

        // Check if live agent was previously enabled
        if (isset($options['live_agent_status']) && $options['live_agent_status'] === 'on') {
            // Disable live agent
            $options['live_agent_status'] = 'off';
            update_option('mxchat_options', $options);

            // Set flag to show the notification banner
            update_option('mxchat_show_live_agent_disabled_notice', true);
        }

        // Mark this update as handled
        update_option('mxchat_live_agent_update_2_2_2_handled', true);
    }
}

/**
 * Handle theme migration notice for version 3.0.1
 * Shows a dismissible notice to Pro users about migrating AI-generated themes
 */
function mxchat_handle_theme_migration_notice() {
    // Get the CURRENT stored version (before it gets updated)
    $current_version = get_option('mxchat_plugin_version', '0.0.0');
    $target_version = '3.0.1';

    // Only run this once for the update to 3.0.1
    $update_handled = get_option('mxchat_theme_migration_update_3_0_1_handled', false);

    // Check if we're upgrading TO 3.0.1 and haven't handled this yet
    if (version_compare($current_version, $target_version, '<') && !$update_handled) {
        // Check if Pro is activated - only show to Pro users
        $license_status = get_option('mxchat_license_status', 'inactive');
        $is_pro = ($license_status === 'active');

        if ($is_pro) {
            // Set flag to show the theme migration notification banner
            update_option('mxchat_show_theme_migration_notice', true);
        }

        // Mark this update as handled (whether Pro or not)
        update_option('mxchat_theme_migration_update_3_0_1_handled', true);
    }
}

// Initialize plugin safely
function mxchat_init() {
    // Include all class files first
    mxchat_include_classes();
    
    // Run update check (this also ensures tables exist)
    mxchat_check_for_update();
    
    // CRITICAL: Ensure tables exist on admin pages (safety net)
    add_action('admin_init', 'mxchat_ensure_tables_exist', 1);
    
    // Add fallback rate limit check
    add_action('init', 'mxchat_check_fallback_rate_limits', 5);
    
    // Add migration notice hook
    add_action('admin_notices', 'mxchat_show_migration_notice');
    add_action('admin_notices', 'mxchat_show_model_access_notice');
    
    // Initialize classes with error handling
    try {
        // Initialize admin classes
        if (is_admin()) {
            if (class_exists('MxChat_Knowledge_Manager')) {
                $mxchat_knowledge_manager = new MxChat_Knowledge_Manager();
                
                if (class_exists('MxChat_Admin')) {
                    $mxchat_admin = new MxChat_Admin($mxchat_knowledge_manager);
                }
            }
            
            // Initialize meta box class
            if (class_exists('MxChat_Meta_Box')) {
                new MxChat_Meta_Box();
            }

        }

        // Initialize content generator globally — it registers wp_head hook
        // for frontend CSS injection, plus wp_ajax_ hooks for admin.
        if (class_exists('MxChat_Content_Generator')) {
            new MxChat_Content_Generator();
        }

        // Initialize cache purge globally — settings writes can happen on any
        // request type (admin screens, admin-ajax autosave, wp-cli), and the
        // deferred-purge cron event fires on front-end requests.
        if (class_exists('MxChat_Cache_Purge')) {
            MxChat_Cache_Purge::init();
        }

        // Initialize REST API globally — endpoints must be registered on
        // every request (admin and frontend) so they're reachable via /wp-json/.
        // Endpoints are auth-gated and locked until the site owner generates
        // a token in MxChat → API Access.
        if (class_exists('MxChat_Rest_Api')) {
            new MxChat_Rest_Api();
        }

        // Initialize public classes
        if (class_exists('MxChat_Public')) {
            $mxchat_public = new MxChat_Public();
        }
        
        if (class_exists('MxChat_Integrator')) {
            global $mxchat_integrator;
            $mxchat_integrator = new MxChat_Integrator();
        }
        
    } catch (Exception $e) {
        //error_log('MxChat initialization error: ' . $e->getMessage());
        
        // Show admin notice if there's an error
        if (is_admin()) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>MxChat Error:</strong> Plugin initialization failed. ';
                echo 'Please check error logs or contact support. Error: ' . esc_html($e->getMessage());
                echo '</p></div>';
            });
        }
    }
}

// Run initialization on plugins_loaded
add_action('plugins_loaded', 'mxchat_init');

// Run migration check on admin init (for auto-updates without reactivation)
add_action('admin_init', 'mxchat_check_and_run_migrations');

/**
 * Check and run migrations on admin init
 * This ensures migrations run even when plugin is auto-updated
 */
function mxchat_check_and_run_migrations() {
    // Only run in admin and not on every request
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    mxchat_migrate_pinecone_roles_add_bot_id();
    mxchat_migrate_add_content_type_column();
    mxchat_migrate_add_translations_table();
    mxchat_migrate_add_session_ratings_table();
}

/**
 * Migration: Create per-session satisfaction ratings table (v3.2.6)
 * For users upgrading from versions before 3.2.6
 */
function mxchat_migrate_add_session_ratings_table() {
    $migration_key = 'mxchat_session_ratings_table_created';
    if (get_option($migration_key)) {
        return;
    }
    mxchat_create_session_ratings_table();
    update_option($migration_key, '3.2.6');
}

/**
 * Migration: Create transcript translations table (v3.0.4)
 * For users upgrading from versions before 3.0.4
 */
function mxchat_migrate_add_translations_table() {
    $migration_key = 'mxchat_translations_table_created';

    // Check if migration already ran
    if (get_option($migration_key)) {
        return;
    }

    // Create the translations table
    mxchat_create_translations_table();

    // Mark migration as complete
    update_option($migration_key, '3.0.4');
}

/**
 * Migration: Update deprecated Gemini embedding model (v3.0.5)
 * Updates gemini-embedding-exp-03-07 to gemini-embedding-001 for users who had it selected
 */
function mxchat_migrate_gemini_embedding_model() {
    $options = get_option('mxchat_options', array());

    if (isset($options['embedding_model']) && $options['embedding_model'] === 'gemini-embedding-exp-03-07') {
        $options['embedding_model'] = 'gemini-embedding-001';
        update_option('mxchat_options', $options);
    }
}

// Register activation hook
register_activation_hook(__FILE__, 'mxchat_activate');

// Add cron schedule
add_filter('cron_schedules', function($schedules) {
    $schedules['one_minute'] = array(
        'interval' => 60,
        'display' => 'Every Minute'
    );
    return $schedules;
});

// Register deactivation hook
register_deactivation_hook(__FILE__, 'mxchat_deactivate');

/**
 * Per-session satisfaction rating: AJAX save handler (v3.2.6).
 * Records one 👍/👎 + optional feedback per chat session. The UNIQUE KEY on
 * session_id makes this naturally idempotent — only the first rating per
 * session is stored; duplicate POSTs are silent no-ops.
 */
function mxchat_save_session_rating() {
    global $wpdb;

    $session_id = isset($_POST['session_id']) ? MxChat_Utils::sanitize_session_id(wp_unslash($_POST['session_id'])) : '';
    $bot_id     = isset($_POST['bot_id']) ? sanitize_text_field(wp_unslash($_POST['bot_id'])) : 'default';
    $rating_raw = isset($_POST['rating']) ? (int) $_POST['rating'] : 0;
    $feedback   = isset($_POST['feedback']) ? sanitize_textarea_field(wp_unslash($_POST['feedback'])) : '';

    if ($session_id === '' || ($rating_raw !== 1 && $rating_raw !== -1)) {
        wp_send_json_error(array('message' => 'invalid_input'), 400);
    }

    if (strlen($feedback) > 1000) {
        $feedback = substr($feedback, 0, 1000);
    }

    $table_name = $wpdb->prefix . 'mxchat_session_ratings';
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table_name WHERE session_id = %s LIMIT 1",
        $session_id
    ));

    if ($existing) {
        if ($feedback !== '') {
            $wpdb->update(
                $table_name,
                array('rating_feedback' => $feedback),
                array('id' => (int) $existing),
                array('%s'),
                array('%d')
            );
        }
        wp_send_json_success(array('updated' => true));
    }

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'session_id'      => $session_id,
            'bot_id'          => $bot_id !== '' ? $bot_id : 'default',
            'rating_value'    => $rating_raw,
            'rating_feedback' => $feedback !== '' ? $feedback : null,
            'created_at'      => current_time('mysql'),
        ),
        array('%s', '%s', '%d', '%s', '%s')
    );

    if ($inserted === false) {
        wp_send_json_error(array('message' => 'db_insert_failed'), 500);
    }

    wp_send_json_success(array('saved' => true));
}
add_action('wp_ajax_mxchat_save_rating', 'mxchat_save_session_rating');
add_action('wp_ajax_nopriv_mxchat_save_rating', 'mxchat_save_session_rating');