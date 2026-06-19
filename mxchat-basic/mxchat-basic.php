<?php
/**
 * Plugin Name: MxChat
 * Plugin URI: https://mxchat.ai/
 * Description: AI chatbot for WordPress with OpenAI, Claude, xAI, DeepSeek, live agent, PDF uploads, WooCommerce, and training on website data.
 * Version: 3.2.10
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
    if (MXCHAT_DEV_MODE) {
        $version .= '.' . time();
    }
    define('MXCHAT_VERSION', $version);
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
        'includes/class-mxchat-tool-registry.php',
        'includes/class-mxchat-integrator.php',
        'includes/class-mxchat-admin.php',
        'includes/class-mxchat-public.php',
        'includes/class-mxchat-utils.php',
        'includes/class-mxchat-user.php',
        'includes/class-mxchat-meta-box.php',
        'includes/class-mxchat-chunker.php',
        'includes/class-mxchat-word-handler.php',
        'includes/class-mxchat-content-generator.php',
        'includes/class-mxchat-cache-purge.php',
        'includes/class-rest-api.php',
        'admin/class-ajax-handler.php',
        'admin/class-pinecone-manager.php',
        'admin/class-knowledge-manager.php'
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

    $current_model = $options['model'];

    // Migrate deprecated Claude models to Claude Opus 4.6 (recommended replacement per Anthropic)
    $deprecated_claude_models = array(
        'claude-3-5-sonnet-20240620',  // Retired Oct 28, 2025
        'claude-3-5-sonnet-20241022',  // Retired Oct 28, 2025
        'claude-3-7-sonnet-20250219',  // Retiring Feb 19, 2026
        'claude-3-opus-20240229',      // Retired Jan 5, 2026
        'claude-3-sonnet-20240229',    // Legacy
        'claude-3-haiku-20240307',     // Legacy
    );
    if (in_array($current_model, $deprecated_claude_models, true)) {
        $options['model'] = 'claude-opus-4-6';
        $migrated = true;
        $migration_message = sprintf(
            'Your chatbot model has been automatically updated from %s to Claude Opus 4.6 due to Anthropic deprecating older Claude models.',
            $current_model
        );
    }

    // Migrate deprecated Claude Haiku 3.5 to Claude Haiku 4.5
    if ($current_model === 'claude-3-5-haiku-20241022') {
        $options['model'] = 'claude-haiku-4-5-20251001';
        $migrated = true;
        $migration_message = 'Your chatbot model has been automatically updated from Claude Haiku 3.5 to Claude Haiku 4.5 due to Anthropic deprecating the older model.';
    }

    // Migrate deprecated GPT-4 series and GPT-3.5 Turbo to GPT-5.1 Chat Latest
    if (in_array($current_model, array('gpt-4o', 'gpt-4.1-2025-04-14', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo'), true)) {
        $options['model'] = 'gpt-5.1-chat-latest';
        $migrated = true;
        $migration_message = sprintf(
            'Your chatbot model has been automatically updated from %s to GPT-5.1 Chat Latest due to OpenAI deprecating older models.',
            $current_model
        );
    }

    // Migrate deprecated GPT-4o Mini and GPT-4.1 Mini to GPT-5 Mini
    if (in_array($current_model, array('gpt-4o-mini', 'gpt-4.1-mini'), true)) {
        $options['model'] = 'gpt-5-mini';
        $migrated = true;
        $migration_message = sprintf(
            'Your chatbot model has been automatically updated from %s to GPT-5 Mini due to OpenAI deprecating GPT-4 series models.',
            $current_model
        );
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

function mxchat_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    //error_log("MxChat: Running activation function");

    // Create chat transcripts table with improved function
    mxchat_create_chat_transcripts_table();

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

    // Update version
    update_option('mxchat_plugin_version', MXCHAT_VERSION);

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
        return;
    }
    
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
        // Only run reset if the MxChat_Integrator class exists
        if (class_exists('MxChat_Integrator')) {
            $integrator = new MxChat_Integrator();
            if (method_exists($integrator, 'mxchat_reset_rate_limits')) {
                $integrator->mxchat_reset_rate_limits();
                update_option('mxchat_next_rate_limit_check', time() + 3600);
            }
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
        $plugin_version = MXCHAT_VERSION;

        // Always ensure critical tables exist (even if version matches)
        // This handles manual table deletion or fresh installs
        $chat_table = $wpdb->prefix . 'mxchat_chat_transcripts';
        $queue_table = $wpdb->prefix . 'mxchat_processing_queue';
        
        $chat_exists = $wpdb->get_var("SHOW TABLES LIKE '$chat_table'") === $chat_table;
        $queue_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'") === $queue_table;
        
        if (!$chat_exists || !$queue_exists) {
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

            // Run full activation to ensure everything is up to date
            mxchat_activate();
            
            // Run migration functions
            mxchat_migrate_live_agent_status();

            // Add the cleanup function for version 2.1.8
            if (version_compare($current_version, '2.1.8', '<')) {
                $deleted = mxchat_cleanup_orphaned_chat_history();
            }

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
    
    $chat_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    $queue_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'") === $queue_table;
    
    if (!$chat_exists || !$queue_exists) {
        //error_log("MxChat: Tables missing on admin load, running activation");
        mxchat_activate();
    }
}

/**
 * Clean up orphaned chat history options from the wp_options table
 * @return int Number of options deleted
 */
function mxchat_cleanup_orphaned_chat_history() {
    global $wpdb;
    $count = 0;

    // Get all option keys that match our pattern
    $history_options = $wpdb->get_results(
        "SELECT option_name FROM {$wpdb->options}
         WHERE option_name LIKE 'mxchat_history_%'"
    );

    if (!empty($history_options)) {
        foreach ($history_options as $option) {
            // Extract the session ID from the option name
            $session_id = str_replace('mxchat_history_', '', $option->option_name);

            // Check if this session still exists in the custom table
            $table_name = $wpdb->prefix . 'mxchat_chat_transcripts';
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE session_id = %s",
                    $session_id
                )
            );

            // If session doesn't exist in the main table, delete the option
            if ($exists == 0) {
                delete_option($option->option_name);
                // Also delete related metadata
                delete_option("mxchat_email_{$session_id}");
                delete_option("mxchat_name_{$session_id}");
                delete_option("mxchat_agent_name_{$session_id}");
                $count++;
            }
        }
    }

    return $count;
}

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

    $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
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