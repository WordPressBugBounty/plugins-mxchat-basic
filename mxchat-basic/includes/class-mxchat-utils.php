<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class MxChat_Utils {

/**
 * True while a storage routine is re-writing an entry it is about to re-add
 * (submit_chunked_content's clean-slate delete). delete_chunks_for_url() skips
 * the Vector Store mirror-delete while set — an internal re-store is not an
 * entry removal, and mirroring it would delete-then-reupload the entry's file
 * on every chunked save (plan 15b5c6).
 */
private static $vectorstore_mirror_suspended = false;

/**
 * Validate a client-supplied session id (plan-mxchat-20260731-d42bec).
 *
 * sanitize_text_field() — which every session_id read site used before this —
 * preserves '/' and '..'. Harmless where the value is only an option or
 * transient key suffix, but mxchat_send_delayed_transcript() interpolates it
 * into a filesystem path, so '../../../../path/x' wrote, emailed and deleted a
 * file outside the uploads dir.
 *
 * REJECTS rather than rewrites: a silently-stripped id would orphan the
 * conversation it belongs to, which is harder to diagnose than a clean refusal.
 * Returns '' for anything malformed, so call sites fall into the empty-session
 * error paths they already have.
 *
 * The generator only ever emits 'mxchat_chat_' + 32 hex chars
 * (class-mxchat-integrator.php, js/chat-script.js), so this is not restrictive
 * in practice. Length ceiling is deliberate — session ids are also used as
 * option-name suffixes, and WP option names cap at 191 chars.
 *
 * @param mixed $raw Raw request value.
 * @return string The id if well-formed, '' otherwise.
 */
public static function sanitize_session_id($raw) {
    if (!is_scalar($raw)) {
        return '';
    }
    $val = trim((string) $raw);
    if ($val === '') {
        return '';
    }
    return preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $val) ? $val : '';
}

/**
 * Sanitize the lead-capture consent-checkbox label (plan b062c4).
 *
 * The label is owner-supplied and renders inside the widget's email form, so
 * this is a security boundary, not a formatting nicety. One explicit
 * allowlist, used at BOTH save time (options.php sanitize + autosave AJAX)
 * and render time (widget form, admin surfaces) so the two can never drift:
 * an anchor — the whole point is "I agree to the <a>Privacy Policy</a>" —
 * plus inline emphasis. No block tags, no images, no style attributes.
 *
 * The stored consent record keeps this exact sanitized string as "the text
 * the visitor saw", so it must be deterministic: same input, same output,
 * whichever path ran it.
 *
 * @param mixed $raw Owner-entered label.
 * @return string Sanitized label, capped at 1000 chars.
 */
public static function sanitize_consent_label($raw) {
    if (!is_scalar($raw)) {
        return '';
    }

    $allowed = array(
        'a'      => array(
            'href'   => true,
            'title'  => true,
            'target' => true,
            'rel'    => true,
        ),
        'strong' => array(),
        'em'     => array(),
        'br'     => array(),
    );

    $label = wp_kses(trim((string) $raw), $allowed);

    return mb_substr($label, 0, 1000);
}

/**
 * Per-request cache for get_session_history(). Mirrors get_option()'s
 * request-scoped caching, which the mxchat_history_ option reads got for
 * free before plan 839c4c moved history reads onto the transcripts table.
 */
private static $history_cache = array();

/**
 * Session chat history read from the transcripts table, in the exact array
 * shape the legacy mxchat_history_<sid> option stored (plan 839c4c). The
 * option was a second copy of state the table already held — measured
 * byte-identical in role/content/order on 174 of 177 real sessions, with
 * the table a superset on the rest — at up to 64 KB per option row. The
 * table is now the single store; nothing writes the option any more.
 *
 * Shape notes, load-bearing for the consumers:
 * - id: the transcripts row id (int). Integer ids make the pollers'
 *   ">" comparisons correct where the old uniqid() strings only worked by
 *   accident of hex ordering.
 * - timestamp: milliseconds, derived from the table's second-resolution GMT
 *   column (x1000). Consumers comparing against a real-millisecond client
 *   cutoff MUST floor the cutoff to the second and err inclusive — see the
 *   persistence-off filters in class-mxchat-integrator.php.
 * - agent_name: the row's user_identifier, which the writer sets to the
 *   same displayed_name value the option carried (agent name when present,
 *   else email, else identifier).
 *
 * Public and static so mxchat-woo / mxchat-forms can call the same accessor
 * as core, guarded with method_exists against an older mxchat-basic.
 *
 * @param string $session_id
 * @return array[] Chronological entries: id, role, content, timestamp, agent_name.
 */
public static function get_session_history($session_id) {
    global $wpdb;

    $session_id = self::sanitize_session_id($session_id);
    if ($session_id === '') {
        return array();
    }

    if (array_key_exists($session_id, self::$history_cache)) {
        return self::$history_cache[$session_id];
    }

    $table = $wpdb->prefix . 'mxchat_chat_transcripts';

    // No SHOW TABLES guard: this is the chat hot path and the table is
    // created on activation (with an admin-load safety net). A genuinely
    // missing table fails the query and yields the same empty history the
    // old option read produced on a fresh session.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, role, message, user_identifier, timestamp
             FROM `$table` WHERE session_id = %s ORDER BY id ASC",
            $session_id
        ),
        ARRAY_A
    );

    $history = array();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            // The column stores GMT (current_time('mysql', 1) at the writer),
            // so pin the parse to UTC rather than the site timezone.
            $ts = strtotime($row['timestamp'] . ' +0000');
            $history[] = array(
                'id'         => (int) $row['id'],
                'role'       => (string) $row['role'],
                'content'    => (string) $row['message'],
                'timestamp'  => ($ts ? $ts : 0) * 1000,
                'agent_name' => (string) $row['user_identifier'],
            );
        }
    }

    self::$history_cache[$session_id] = $history;

    return $history;
}

/**
 * Drop the cached history for one session (or all). The writer calls this
 * after every insert so a later read in the same request — e.g. the AI
 * context build that follows saving the user's message — sees the new row,
 * matching the read-your-own-write behavior update_option() gave the old
 * option copy.
 *
 * @param string|null $session_id Null flushes everything (test seam).
 */
public static function flush_session_history_cache($session_id = null) {
    if ($session_id === null) {
        self::$history_cache = array();
        return;
    }

    unset(self::$history_cache[(string) $session_id]);
}

/**
 * Most recipients the Notification Email field will accept (plan 2f131a).
 * A settings field is not a mailing list.
 */
const NOTIFICATION_EMAIL_MAX = 5;

/**
 * Parse the Notification Email field into a list of recipients (plan 2f131a).
 *
 * THE TRAP THIS EXISTS TO CLOSE: sanitize_email() cannot be the validator for
 * this field, because its output for the failing input is VALID. WordPress
 * strips the separator and the surplus '@' and concatenates the remains:
 *
 *   support@acme.com, sales@acme.com  ->  support@acme.comsalesacme.com
 *
 * and is_email() then returns true on that. So every guard in the plugin passed,
 * the address was stored, the autosave ticked green, and both the new-session
 * notification and the auto-emailed transcript went to a domain that does not
 * exist — with no error anywhere. Validating the RAW part BEFORE sanitizing is
 * the whole point; reversing those two lines silently restores the bug.
 *
 * All-or-nothing by design: if any entry is bad the caller must store NOTHING.
 * A partial accept — keeping the good addresses and dropping the bad one — is
 * the same defect in a new costume, because the owner still believes everyone
 * on their list is being notified.
 *
 * @param mixed $raw Raw field value, exactly as submitted.
 * @return array{emails: string[], error: string} Empty emails + empty error
 *                                                means the field was empty.
 */
public static function parse_notification_emails($raw) {
    $out = array('emails' => array(), 'error' => '');

    if (!is_scalar($raw)) {
        $out['error'] = __('The notification email could not be read.', 'mxchat');
        return $out;
    }

    $raw = trim((string) $raw);
    if ($raw === '') {
        return $out;   // genuinely empty — the caller falls back to admin_email
    }

    $seen = array();
    foreach (preg_split('/[,;]/', $raw) as $part) {
        $part = trim($part);
        if ($part === '') {
            // A trailing or doubled separator carries no address, so skipping it
            // cannot silently drop a recipient. This is the ONLY thing tolerated.
            continue;
        }

        // RAW first. See the note above — order is load-bearing.
        $clean = is_email($part) ? sanitize_email($part) : '';
        if ($clean === '' || !is_email($clean)) {
            return array(
                'emails' => array(),
                'error'  => sprintf(
                    /* translators: %s: the email address the owner typed. */
                    __('"%s" is not a valid email address, so nothing was saved. Separate multiple addresses with a comma.', 'mxchat'),
                    esc_html($part)
                ),
            );
        }

        $key = strtolower($clean);
        if (isset($seen[$key])) {
            continue;   // same address twice would simply mail them twice
        }
        $seen[$key] = true;
        $out['emails'][] = $clean;
    }

    if (count($out['emails']) > self::NOTIFICATION_EMAIL_MAX) {
        return array(
            'emails' => array(),
            'error'  => sprintf(
                /* translators: %d: maximum number of notification recipients. */
                __('Enter at most %d email addresses, separated by commas.', 'mxchat'),
                self::NOTIFICATION_EMAIL_MAX
            ),
        );
    }

    return $out;
}

/**
 * The stored recipient list, ready to hand to wp_mail() (plan 2f131a).
 *
 * Fallback rule, and it is narrow on purpose: an EMPTY field falls back to the
 * site admin address, because that is the documented behaviour and an owner who
 * never filled the field in still wants their notifications. A field holding
 * something unusable does NOT fall back — it sends nowhere, exactly as before
 * this plan. Falling back on bad input would mean a typo silently redirects a
 * store's transcripts to a different mailbox than the one on screen.
 *
 * @param array|null $options mxchat_transcripts_options, or null to read it.
 * @return string[] Recipients; empty means do not send.
 */
public static function notification_recipients($options = null) {
    if (!is_array($options)) {
        $options = get_option('mxchat_transcripts_options', array());
        if (!is_array($options)) {
            $options = array();
        }
    }

    $raw = isset($options['mxchat_notification_email']) ? $options['mxchat_notification_email'] : '';
    $raw = is_scalar($raw) ? trim((string) $raw) : '';

    if ($raw === '') {
        $admin = get_option('admin_email');
        return is_email($admin) ? array($admin) : array();
    }

    $parsed = self::parse_notification_emails($raw);
    return $parsed['error'] === '' ? $parsed['emails'] : array();
}

/**
 * Centralized embedding model registry. Single source of truth for dimensions
 * and provider, so model-switch protection logic doesn't drift across files.
 */
public static function embedding_model_registry() {
    return array(
        'text-embedding-ada-002' => array('dims' => 1536, 'provider' => 'openai',  'label' => 'Ada 2'),
        'text-embedding-3-small' => array('dims' => 1536, 'provider' => 'openai',  'label' => 'TE3 Small'),
        'text-embedding-3-large' => array('dims' => 3072, 'provider' => 'openai',  'label' => 'TE3 Large'),
        'voyage-3-large'         => array('dims' => 2048, 'provider' => 'voyage',  'label' => 'Voyage-3 Large'),
        'gemini-embedding-001'   => array('dims' => 1536, 'provider' => 'gemini',  'label' => 'Gemini Embedding'),
    );
}

public static function embedding_model_dimensions($model) {
    $registry = self::embedding_model_registry();
    return isset($registry[$model]) ? (int) $registry[$model]['dims'] : 0;
}

public static function embedding_model_label($model) {
    if (is_string($model) && strpos($model, 'custom:') === 0) {
        /* translators: %s: the embedding model name configured on the custom provider */
        return sprintf(__('%s (custom provider)', 'mxchat'), substr($model, 7));
    }
    $registry = self::embedding_model_registry();
    return isset($registry[$model]) ? $registry[$model]['label'] : $model;
}

/**
 * Returns the model that was last used to actually write embeddings into the
 * KB. Differs from the user-selected setting once a switch has happened but
 * no re-embed has occurred yet — that's the mismatch state we warn about.
 */
public static function get_active_embedding_model() {
    return get_option('mxchat_active_embedding_model', '');
}

/**
 * Stamp the model that produced the most recent successful embedding. Called
 * from generate_embedding() right after the API responds with a valid vector.
 */
public static function stamp_active_embedding_model($model) {
    if (!empty($model) && $model !== self::get_active_embedding_model()) {
        update_option('mxchat_active_embedding_model', $model, false);
    }
}

/**
 * The model name the custom-provider embedding path will send, mirroring the
 * fallback chain the request itself uses: dedicated custom embedding model,
 * else the custom chat model, else 'default'. Single source shared by
 * generate_embedding_custom() and the mismatch-warning "selected" side so the
 * two can never drift (plan ae02cb).
 */
public static function resolve_custom_embedding_model($options) {
    if (isset($options['custom_provider_embedding_model']) && trim((string) $options['custom_provider_embedding_model']) !== '') {
        return trim((string) $options['custom_provider_embedding_model']);
    }
    if (isset($options['custom_provider_model']) && trim((string) $options['custom_provider_model']) !== '') {
        return trim((string) $options['custom_provider_model']);
    }
    return 'default';
}

/**
 * The EFFECTIVE selected embedding model — what the next embed will actually
 * use. With custom-provider embeddings on this is the custom identity in the
 * same 'custom:<model>' form stamp_active_embedding_model() records, not the
 * inert standard dropdown value. Mismatch-warning comparisons must read this,
 * never $options['embedding_model'] directly — the dropdown cannot be
 * deselected, so reading it raw flags every correctly-configured custom setup.
 */
public static function get_selected_embedding_model($options = null) {
    if (!is_array($options)) {
        $options = get_option('mxchat_options', array());
    }
    if (isset($options['custom_provider_for_embeddings']) && $options['custom_provider_for_embeddings'] === 'on') {
        return 'custom:' . self::resolve_custom_embedding_model($options);
    }
    return $options['embedding_model'] ?? '';
}

/**
 * Extract the 11-character YouTube video ID from a URL, or '' if the URL is
 * not a single-video YouTube link. Single source of truth for both the KB
 * ingestion side and the chat render side — do not duplicate this parsing.
 * Channel, playlist, and search URLs deliberately return '' (only a URL that
 * identifies one video can be embedded).
 */
public static function parse_youtube_id($url) {
    if (!is_string($url) || $url === '' || !preg_match('#^https?://#i', $url)) {
        return '';
    }
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^(www|m)\./', '', $host);
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    $id = '';
    if ($host === 'youtu.be') {
        $segments = explode('/', ltrim($path, '/'));
        $id = $segments[0] ?? '';
    } elseif (in_array($host, array('youtube.com', 'youtube-nocookie.com'), true)) {
        if (preg_match('#^/(?:shorts|embed|live|v)/([A-Za-z0-9_-]+)#', $path, $m)) {
            $id = $m[1];
        } elseif ($path === '/watch') {
            parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query_vars);
            $id = isset($query_vars['v']) ? (string) $query_vars['v'] : '';
        }
    }
    $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $id);
    return (strlen($id) === 11) ? $id : '';
}

/**
 * plan-mxchat-20260813-f52492 — video-card gating.
 *
 * Two standalone options (NOT mxchat_options — they skip the sanitize/autosave
 * traps entirely), read here so the gate in the integrator and the fields on
 * Knowledge -> Chunking & Retrieval can never disagree about a default.
 *
 * Master switch. Default ON: the card is existing behavior, and this is an
 * opt-out for owners who never want one, not a new feature to opt into.
 */
public static function video_embed_enabled() {
    return get_option('mxchat_video_embed_enabled', 'on') === 'on';
}

/**
 * The video card's OWN confidence floor, as a 0-1 cosine — deliberately not
 * the site-wide Similarity Threshold (default 35). "Good enough to quote in
 * the answer" and "good enough to put a video on screen" are different
 * questions: retrieval is allowed to be generous because the model still
 * decides what to say, whereas the card is asserted to the visitor with no
 * such filter. Stored as an int percentage to match the site-wide slider's
 * convention; the default (55) sits above it on purpose.
 *
 * MXCHAT_VIDEO_EMBED_THRESHOLD_DEFAULT is the single source of that number.
 */
public static function video_embed_threshold() {
    $stored = get_option('mxchat_video_embed_threshold', null);
    $percent = ($stored === null || $stored === '')
        ? MXCHAT_VIDEO_EMBED_THRESHOLD_DEFAULT
        : (int) $stored;
    if ($percent < 0)   { $percent = 0; }
    if ($percent > 100) { $percent = 100; }
    // Cast: PHP evaluates 100/100 to int(1), so an unclamped return type would
    // vary with the stored value. Callers compare against a cosine — keep it float.
    return (float) $percent / 100;
}

/**
 * UPDATED: Submit or update content (and its embedding) in the database.
 * Stores in Pinecone if enabled, otherwise stores in WordPress DB.
 *
 * @param string $content      The content to be embedded.
 * @param string $source_url   The source URL of the content.
 * @param string $api_key      The API key used for generating embeddings.
 * @param string $vector_id    Optional vector ID for Pinecone (if not provided, will use md5 of URL)
 * @param string $bot_id       The bot ID for multi-bot support
 * @param string $content_type The type of content (post, page, pdf, url, manual, product, etc.)
 * @return bool|WP_Error True on success, WP_Error on failure
 */
public static function submit_content_to_db($content, $source_url, $api_key, $vector_id = null, $bot_id = 'default', $content_type = 'content') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_system_prompt_content';

    //error_log('[MXCHAT-DB] Starting database submission for URL: ' . $source_url . ' (Bot: ' . $bot_id . ', Type: ' . $content_type . ')');
    //error_log('[MXCHAT-DB] Content length: ' . strlen($content) . ' bytes');

    // Sanitize the source URL. Internal identities (mxchat:// manual docs,
    // upload:// file uploads) are NOT web URLs — esc_url_raw EMPTIES them
    // because its protocol list is statically cached and effectively
    // unfilterable (measured, plan 945406 / 0485e5) — sanitize those as text
    // so upserts stay keyed to a stable identity across re-imports.
    if (preg_match('#^(mxchat|upload)://#i', $source_url)) {
        $source_url = sanitize_text_field($source_url);
    } else {
        $source_url = esc_url_raw($source_url);
    }

    // Sanitize content_type
    $content_type = sanitize_key($content_type);
    if (empty($content_type)) {
        $content_type = 'content'; // Fallback for backwards compatibility
    }

    // Just ensure UTF-8 validity without aggressive escaping
    $safe_content = wp_check_invalid_utf8($content);
    // Remove only null bytes and other control characters, but preserve newlines (\n = \x0A) and carriage returns (\r = \x0D)
    $safe_content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $safe_content);

    // Check if chunking should be applied
    $chunker = MxChat_Chunker::from_settings();
    if ($chunker->should_chunk($safe_content)) {
        //error_log('[MXCHAT-DB] Content exceeds chunk threshold, using chunked submission');
        $chunk_result = self::submit_chunked_content($safe_content, $source_url, $api_key, $bot_id, $content_type, $chunker);
        // Vector Store mirror gets the entry WHOLE — one file per KB entry,
        // OpenAI chunks server-side; local chunking is our own embedding
        // concern and never reaches the store (plan 15b5c6).
        if ($chunk_result === true && class_exists('MxChat_Vectorstore_Manager')) {
            MxChat_Vectorstore_Manager::sync_upsert_entry($source_url, $safe_content, $bot_id, $content_type);
        }
        return $chunk_result;
    }

    // UPDATED: Generate the embedding using bot-specific configuration
    $embedding_vector = self::generate_embedding($content, $api_key, $bot_id);

    if (!is_array($embedding_vector)) {
        // Surface the provider's real reason instead of a fixed string (4a7c0a).
        $reason = is_wp_error($embedding_vector)
            ? $embedding_vector->get_error_message()
            : 'Failed to generate embedding for content';
        return new WP_Error('embedding_failed', $reason);
    }

    //error_log('[MXCHAT-DB] Embedding generated successfully');

    // UPDATED: Check if Pinecone is enabled for this specific bot
    if (self::is_pinecone_enabled_for_bot($bot_id)) {
        //error_log('[MXCHAT-DB] Pinecone is enabled for bot ' . $bot_id . ' - using Pinecone storage');
        // Store in Pinecone only
        $result = self::store_in_pinecone_only($embedding_vector, $content, $source_url, $vector_id, $bot_id, $content_type);
        // If this URL previously stored as CHUNKED content and the new content fits in a
        // single vector, the upsert above only overwrote the base id — the old
        // md5(url)_chunk_N vectors would keep serving the stale text. Sweep them.
        // Only for a real source_url: chunk ids derive from it, and md5('') is shared
        // by legacy URL-less entries so a blind sweep there could hit other entries.
        if ($result === true && !empty($source_url)) {
            self::cleanup_pinecone_chunk_stragglers($source_url, $bot_id);
        }
        if ($result === true && class_exists('MxChat_Vectorstore_Manager')) {
            MxChat_Vectorstore_Manager::sync_upsert_entry($source_url, $safe_content, $bot_id, $content_type);
        }
        return $result;
    } else {
        //error_log('[MXCHAT-DB] Pinecone not enabled for bot ' . $bot_id . ' - using WordPress storage');
        // Store in WordPress database only
        $embedding_vector_serialized = maybe_serialize($embedding_vector);
        $result = self::store_in_wordpress_db($safe_content, $source_url, $embedding_vector_serialized, $table_name, $content_type);
        if ($result === true && class_exists('MxChat_Vectorstore_Manager')) {
            MxChat_Vectorstore_Manager::sync_upsert_entry($source_url, $safe_content, $bot_id, $content_type);
        }
        return $result;
    }
}

/**
 * UPDATED: Check if Pinecone is enabled and properly configured for a specific bot
 */
private static function is_pinecone_enabled_for_bot($bot_id = 'default') {
    // For default bot or when multi-bot is not active, use original method
    if ($bot_id === 'default' || !class_exists('MxChat_Multi_Bot_Manager')) {
        return self::is_pinecone_enabled();
    }
    
    // Get bot-specific Pinecone configuration
    $bot_pinecone_config = apply_filters('mxchat_get_bot_pinecone_config', array(), $bot_id);
    
    if (empty($bot_pinecone_config)) {
        // Fallback to default configuration
        return self::is_pinecone_enabled();
    }
    
    $enabled_check = !empty($bot_pinecone_config['use_pinecone']) && $bot_pinecone_config['use_pinecone'];
    $api_key_check = !empty($bot_pinecone_config['api_key']);
    $host_check = !empty($bot_pinecone_config['host']);
    
    return $enabled_check && $api_key_check && $host_check;
}

/**
 * Check if Pinecone is enabled and properly configured (original method for default bot)
 */
private static function is_pinecone_enabled() {
    $pinecone_options = get_option('mxchat_pinecone_addon_options');
    
    if (empty($pinecone_options)) {
        return false;
    }
    
    $enabled_check = !empty($pinecone_options['mxchat_use_pinecone']) && $pinecone_options['mxchat_use_pinecone'] !== '0';
    $api_key_check = !empty($pinecone_options['mxchat_pinecone_api_key']);
    $host_check = !empty($pinecone_options['mxchat_pinecone_host']);
    
    return $enabled_check && $api_key_check && $host_check;
}

/**
 * UPDATED: Store content in Pinecone only with bot support
 */
private static function store_in_pinecone_only($embedding_vector, $content, $source_url, $vector_id = null, $bot_id = 'default', $content_type = 'content') {
    //error_log('[MXCHAT-PINECONE] ===== Using Pinecone-only storage for bot ' . $bot_id . ' =====');

    // Get bot-specific Pinecone configuration
    if ($bot_id === 'default' || !class_exists('MxChat_Multi_Bot_Manager')) {
        $pinecone_options = get_option('mxchat_pinecone_addon_options');
        $api_key = $pinecone_options['mxchat_pinecone_api_key'];
        $environment = $pinecone_options['mxchat_pinecone_environment'] ?? '';
        $index_name = $pinecone_options['mxchat_pinecone_index'] ?? '';
        $namespace = $pinecone_options['mxchat_pinecone_namespace'] ?? '';
    } else {
        $bot_pinecone_config = apply_filters('mxchat_get_bot_pinecone_config', array(), $bot_id);
        if (empty($bot_pinecone_config)) {
            // Fallback to default configuration
            $pinecone_options = get_option('mxchat_pinecone_addon_options');
            $api_key = $pinecone_options['mxchat_pinecone_api_key'];
            $environment = $pinecone_options['mxchat_pinecone_environment'] ?? '';
            $index_name = $pinecone_options['mxchat_pinecone_index'] ?? '';
            $namespace = $pinecone_options['mxchat_pinecone_namespace'] ?? '';
        } else {
            $api_key = $bot_pinecone_config['api_key'];
            $environment = ''; // Not used in new Pinecone API
            $index_name = ''; // Not used in new Pinecone API
            $namespace = $bot_pinecone_config['namespace'] ?? '';
        }
    }

    $result = self::store_in_pinecone_main(
        $embedding_vector,
        $content,
        $source_url,
        $api_key,
        $environment,
        $index_name,
        $vector_id,
        $bot_id,
        $namespace,
        $content_type
    );
    
    if (is_wp_error($result)) {
        //error_log('[MXCHAT-PINECONE] Pinecone storage failed for bot ' . $bot_id . ': ' . $result->get_error_message());
        return $result;
    }
    
    //error_log('[MXCHAT-PINECONE] Pinecone storage completed successfully for bot ' . $bot_id);
    return true;
}

/**
 * Store content in WordPress database with progressive fallback
 * UPDATED 2.5.6: Now includes content_type parameter
 */
private static function store_in_wordpress_db($safe_content, $source_url, $embedding_vector_serialized, $table_name, $content_type = 'content') {
    global $wpdb;

    //error_log('[MXCHAT-DB] ===== Using WordPress-only storage =====');

    // Sanitize content_type
    $content_type = sanitize_key($content_type);
    if (empty($content_type)) {
        $content_type = 'content'; // Fallback for backwards compatibility
    }

    // ===== FIXED: Generate unique identifier for manual content =====
    $original_source_url = $source_url;
    // Check if this is truly manual content (no URL at all) vs a real URL that filter_var rejects
    // filter_var(FILTER_VALIDATE_URL) rejects valid URLs with encoded chars, non-ASCII, fragments, etc.
    // Use a looser check: if it starts with http(s):// or has a scheme, it's a URL
    $has_url_scheme = !empty($source_url) && preg_match('#^https?://#i', $source_url);
    // upload:// identities (admin document/PDF uploads, plan 0485e5) are stable
    // and deduplicable — treat them like URLs so a re-upload UPDATES the row
    // instead of minting a fresh manual identity (which would duplicate).
    $has_stable_identity = $has_url_scheme || (!empty($source_url) && preg_match('#^upload://#i', $source_url));
    // Treat legacy mxchat.ai source URLs as manual — old bug assigned the site URL to manual entries
    $is_legacy_mxchat_url = $has_url_scheme && strpos($source_url, 'mxchat.ai') !== false;
    $is_manual_content = empty($source_url) || $source_url === '' || !$has_stable_identity || $is_legacy_mxchat_url;

    if ($is_manual_content) {
        // Generate unique identifier for manual content to prevent overwrites
        $source_url = 'mxchat://manual-content/' . time() . '-' . wp_generate_password(8, false);
        //error_log('[MXCHAT-DB] Generated unique ID for manual content: ' . $source_url);
    }

    // Only check for duplicates if we have a valid source URL (not manual content)
    $existing_id = null;
    if (!$is_manual_content) {
        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE source_url = %s LIMIT 1",
                $source_url
            )
        );
        //error_log('[MXCHAT-DB] Checked for existing URL, found ID: ' . ($existing_id ?: 'none'));
    } else {
        //error_log('[MXCHAT-DB] Manual content - will create new entry (no duplicate check)');
    }
    // ===== END FIX =====

    // Progressive fallback mechanism for problematic content
    $attempt = 1;
    $max_attempts = 3;
    $current_content = $safe_content;
    $result = false;

    while ($attempt <= $max_attempts && $result === false) {
        try {
            if ($existing_id) {
                //error_log('[MXCHAT-DB] Found existing entry (ID: ' . $existing_id . '). Updating... (Attempt ' . $attempt . ')');

                // Update the existing row - UPDATED 2.5.6: Added content_type
                $result = $wpdb->update(
                    $table_name,
                    array(
                        'url'              => $source_url,
                        'article_content'  => $current_content,
                        'embedding_vector' => $embedding_vector_serialized,
                        'source_url'       => $source_url,
                        'content_type'     => $content_type,
                        'timestamp'        => current_time('mysql'),
                    ),
                    array('id' => $existing_id),
                    array('%s','%s','%s','%s','%s','%s'),
                    array('%d')
                );
            } else {
                //error_log('[MXCHAT-DB] No existing entry found. Inserting new row... (Attempt ' . $attempt . ')');
                //error_log('[MXCHAT-DB] Content sample: ' . substr($current_content, 0, 1000));

                // Insert a new row - UPDATED 2.5.6: Added content_type
                $result = $wpdb->insert(
                    $table_name,
                    array(
                        'url'              => $source_url, // Now unique for manual content
                        'article_content'  => $current_content,
                        'embedding_vector' => $embedding_vector_serialized,
                        'source_url'       => $source_url, // Now unique for manual content
                        'content_type'     => $content_type,
                        'timestamp'        => current_time('mysql'),
                    ),
                    array('%s','%s','%s','%s','%s','%s')
                );
            }
            
        if ($result === false) {
            //error_log('[MXCHAT-DB] Database operation failed (Attempt ' . $attempt . ')');
            //error_log('[MXCHAT-DB] MySQL Error: ' . $wpdb->last_error);
            //error_log('[MXCHAT-DB] MySQL Error Number: ' . $wpdb->last_errno);
            //error_log('[MXCHAT-DB] Last Query: ' . substr($wpdb->last_query, 0, 500));
            //error_log('[MXCHAT-DB] Content length: ' . strlen($current_content) . ' bytes');
            //error_log('[MXCHAT-DB] Embedding vector length: ' . strlen($embedding_vector_serialized) . ' bytes');
                
                // Progressively apply more aggressive sanitization on failure
                if ($attempt === 1) {
                    // First fallback: Use a more aggressive character filter and shorten
                    $current_content = preg_replace('/[^\p{L}\p{N}\s.,;:!?()-]/u', '', $current_content);
                    $current_content = substr($current_content, 0, 50000);
                } else if ($attempt === 2) {
                    // Second fallback: Keep only alphanumeric and basic punctuation, shorten further
                    $current_content = preg_replace('/[^a-zA-Z0-9\s.,;:!?()-]/u', '', $current_content);
                    $current_content = substr($current_content, 0, 30000);
                }
                
                $attempt++;
            }
        } catch (Exception $e) {
            //error_log('[MXCHAT-DB] Exception during database operation: ' . $e->getMessage());
            $attempt++;
        }
    }
    
if ($result === false) {
    //error_log('[MXCHAT-DB] All database operation attempts failed');
    //error_log('[MXCHAT-DB] Final MySQL Error: ' . $wpdb->last_error);
    
    $detailed_error = sprintf(
        'Failed to store content in WordPress database after %d attempts. MySQL Error: %s (Error #%d). Content size: %d bytes, Embedding size: %d bytes',
        $max_attempts,
        $wpdb->last_error,
        $wpdb->last_errno,
        strlen($current_content),
        strlen($embedding_vector_serialized)
    );
    
    return new WP_Error('database_failed', $detailed_error);
}
    
    //error_log('[MXCHAT-DB] WordPress database operation completed successfully (Attempt ' . ($attempt - 1) . ')');
    return true;
}

/**
 * UPDATED: Store content in Pinecone database with bot support
 * UPDATED 2.5.6: Now accepts content_type parameter
 */
private static function store_in_pinecone_main($embedding_vector, $content, $url, $api_key, $environment, $index_name, $vector_id = null, $bot_id = 'default', $namespace = '', $content_type = 'content') {
    //error_log('[MXCHAT-PINECONE-MAIN] ===== Starting Pinecone storage for bot ' . $bot_id . ' =====');

    // ===== UPDATED: Handle manual content with unique vector IDs =====
    if ($vector_id) {
        // Use provided vector ID
        //error_log('[MXCHAT-PINECONE-MAIN] Using provided vector ID: ' . $vector_id);
    } elseif (!empty($url) && preg_match('#^(https?|upload)://#i', $url)) {
        // For URLs — and stable upload:// identities (plan 0485e5) — use an
        // identity-derived ID so a re-import upserts the same vector.
        $vector_id = md5($url);
        //error_log('[MXCHAT-PINECONE-MAIN] Generated vector ID from URL: ' . $vector_id);
    } else {
        // For manual content (empty/no URL scheme), generate unique ID
        $vector_id = 'manual_' . time() . '_' . substr(md5($content . microtime(true)), 0, 8);
        //error_log('[MXCHAT-PINECONE-MAIN] Generated unique vector ID for manual content: ' . $vector_id);
    }
    // ===== END UPDATE =====

    // Get host from bot-specific config or fallback to default
    if ($bot_id === 'default' || !class_exists('MxChat_Multi_Bot_Manager')) {
        $options = get_option('mxchat_pinecone_addon_options');
        $host = $options['mxchat_pinecone_host'] ?? '';
    } else {
        $bot_pinecone_config = apply_filters('mxchat_get_bot_pinecone_config', array(), $bot_id);
        if (!empty($bot_pinecone_config)) {
            $host = $bot_pinecone_config['host'] ?? '';
        } else {
            $options = get_option('mxchat_pinecone_addon_options');
            $host = $options['mxchat_pinecone_host'] ?? '';
        }
    }

    //error_log('[MXCHAT-PINECONE-MAIN] Host: ' . $host);
    //error_log('[MXCHAT-PINECONE-MAIN] API key length: ' . strlen($api_key));
    //error_log('[MXCHAT-PINECONE-MAIN] Bot ID: ' . $bot_id);
    //error_log('[MXCHAT-PINECONE-MAIN] Namespace: ' . $namespace);

    if (empty($host)) {
        //error_log('[MXCHAT-PINECONE-MAIN] ERROR: Host is empty');
        return new WP_Error('pinecone_config', 'Pinecone host is not configured. Please set the host in your bot settings.');
    }

    // ===== UPDATED 2.5.6: Use passed content_type or determine from URL if not provided =====
    // Sanitize content_type
    $content_type = sanitize_key($content_type);
    if (empty($content_type)) {
        // Fallback to old detection logic for backwards compatibility
        $is_product = false;
        $content_type = 'manual'; // Default for manual content

        if (!empty($url) && preg_match('#^https?://#i', $url)) {
            $is_product = (strpos($url, '/product/') !== false || strpos($url, '/shop/') !== false);
            $content_type = $is_product ? 'product' : 'content';
        }
    }

    //error_log('[MXCHAT-PINECONE-MAIN] Content type: ' . $content_type);
    // ===== END UPDATE =====

    $api_endpoint = "https://{$host}/vectors/upsert";
    //error_log('[MXCHAT-PINECONE-MAIN] API endpoint: ' . $api_endpoint);

    // UPDATED 2.5.6: Use provided content_type in metadata
    $metadata = array(
        'text' => $content,
        'source_url' => $url, // Can be empty for manual content
        'type' => $content_type, // Now supports: post, page, pdf, url, manual, product, etc.
        'last_updated' => time(),
        'created_at' => time(), // Add creation timestamp
        'bot_id' => $bot_id, // Add bot identification
    );
    
    $vector_data = array(
        'id' => $vector_id,
        'values' => $embedding_vector,
        'metadata' => $metadata
    );
    
    $request_body = array(
        'vectors' => array($vector_data)
    );
    
    // Add namespace if specified for multi-bot separation
    if (!empty($namespace)) {
        $request_body['namespace'] = $namespace;
        //error_log('[MXCHAT-PINECONE-MAIN] Using namespace: ' . $namespace);
    }
    
    //error_log('[MXCHAT-PINECONE-MAIN] Request body prepared (embedding dimensions: ' . count($embedding_vector) . ')');

    $response = wp_remote_post($api_endpoint, array(
        'headers' => array(
            'Api-Key' => $api_key,
            'accept' => 'application/json',
            'content-type' => 'application/json'
        ),
        'body' => wp_json_encode($request_body),
        'timeout' => 30,
        'data_format' => 'body'
    ));

    if (is_wp_error($response)) {
        //error_log('[MXCHAT-PINECONE-MAIN] WordPress request error: ' . $response->get_error_message());
        return new WP_Error('pinecone_request', $response->get_error_message());
    }

    $response_code = wp_remote_retrieve_response_code($response);
    //error_log('[MXCHAT-PINECONE-MAIN] Response code: ' . $response_code);
    
    if ($response_code !== 200) {
        $body = wp_remote_retrieve_body($response);
        //error_log('[MXCHAT-PINECONE-MAIN] API error - Response body: ' . $body);
        return new WP_Error('pinecone_api', sprintf(
            'Pinecone API error (HTTP %d): %s',
            $response_code,
            $body
        ));
    }

    $response_body = wp_remote_retrieve_body($response);
    //error_log('[MXCHAT-PINECONE-MAIN] Success response: ' . $response_body);
    //error_log('[MXCHAT-PINECONE-MAIN] Successfully stored in Pinecone for bot ' . $bot_id);
    //error_log('[MXCHAT-PINECONE-MAIN] ===== Pinecone storage complete =====');
    
    return true;
}

/**
 * Caller-side pre-flight for KB ingestion: can an embedding request be made
 * with these options, and which API key should travel downstream?
 *
 * Custom-provider-aware — generate_embedding() below routes to the custom
 * endpoint FIRST and ignores the passed cloud key entirely when
 * custom_provider_for_embeddings is on, so on that branch the only real
 * requirement is a Base URL. Ingestion callers that gated on a cloud API key
 * were killing keyless custom-embeddings sites (local Ollama / LM Studio
 * class) before the embed layer could route (plan cbd5fd).
 *
 * NOTE: reads $options['embedding_model'] raw on purpose — this mirrors
 * generate_embedding()'s own routing read, NOT the mismatch-banner's
 * "selected" chain (get_selected_embedding_model). The helper must predict
 * what the very next embed call will do, byte-for-byte.
 *
 * Decision only — callers keep their own error-surfacing shape (admin-notice
 * transient + redirect, wp_send_json_error, WP_Error, silent return).
 *
 * @param array|null $options Resolved options (bot-specific where the caller
 *                            has them); null loads the default bot's options.
 * @return array {
 *     @type bool   $ok       Whether ingestion can proceed.
 *     @type string $api_key  Key to pass downstream ('' on the custom branch —
 *                            generate_embedding() ignores it there).
 *     @type string $reason   Human-readable blocker; '' when $ok.
 *     @type string $provider Short provider label ('OpenAI', 'Voyage AI',
 *                            'Google Gemini', 'Custom Provider').
 * }
 */
public static function embedding_preflight($options = null) {
    if (!is_array($options)) {
        $options = get_option('mxchat_options');
        $options = is_array($options) ? $options : array();
    }

    // Custom branch mirrors generate_embedding()'s routing order (custom first).
    if (isset($options['custom_provider_for_embeddings']) && $options['custom_provider_for_embeddings'] === 'on') {
        $base_url = isset($options['custom_provider_base_url']) ? rtrim(trim((string) $options['custom_provider_base_url']), '/') : '';
        if ($base_url === '') {
            return array(
                'ok'       => false,
                'api_key'  => '',
                // Same string generate_embedding_custom() returns for this state.
                'reason'   => __('Custom provider Base URL is not configured.', 'mxchat'),
                'provider' => 'Custom Provider',
            );
        }
        return array('ok' => true, 'api_key' => '', 'reason' => '', 'provider' => 'Custom Provider');
    }

    $selected_model = $options['embedding_model'] ?? 'text-embedding-ada-002';
    if (strpos($selected_model, 'voyage') === 0) {
        $api_key  = $options['voyage_api_key'] ?? '';
        $provider = 'Voyage AI';
    } elseif (strpos($selected_model, 'gemini-embedding') === 0) {
        $api_key  = $options['gemini_api_key'] ?? '';
        $provider = 'Google Gemini';
    } else {
        $api_key  = $options['api_key'] ?? '';
        $provider = 'OpenAI';
    }

    if (empty($api_key)) {
        return array(
            'ok'       => false,
            'api_key'  => '',
            'reason'   => sprintf(
                /* translators: %s: embedding provider name */
                __('%s API key is not configured. Please add your API key in the settings before submitting content.', 'mxchat'),
                $provider
            ),
            'provider' => $provider,
        );
    }

    return array('ok' => true, 'api_key' => $api_key, 'reason' => '', 'provider' => $provider);
}

/**
 * Public QUERY-side entry point (plan 876edb). The chat pipeline's
 * MxChat_Integrator::mxchat_generate_embedding() adapter routes through here
 * so the query and index sides share ONE provider-routing implementation —
 * the same endpoints, request bodies, and stamping semantics. The Integrator
 * keeps its own error vocabulary by translating the WP_Error this returns
 * (see the structured error data on every failure path below).
 *
 * @param string $text    The text to be embedded.
 * @param string $api_key Caller-resolved API key (per-bot on the query side).
 * @param string $bot_id  The bot ID for multi-bot support.
 * @return array|WP_Error The embedding vector, or WP_Error carrying the reason.
 */
public static function generate_query_embedding($text, $api_key, $bot_id = 'default') {
    return self::generate_embedding($text, $api_key, $bot_id);
}

/**
 * UPDATED: Generate an embedding for the given text using bot-specific configuration.
 *
 * @param string $text    The text to be embedded.
 * @param string $api_key The API key used for generating embeddings.
 * @param string $bot_id  The bot ID for multi-bot support
 * @return array|null     The embedding vector or null on failure.
 */
private static function generate_embedding($text, $api_key, $bot_id = 'default') {
    // Get bot-specific options
    if ($bot_id === 'default' || !class_exists('MxChat_Multi_Bot_Manager')) {
        $options = get_option('mxchat_options');
    } else {
        $bot_options = apply_filters('mxchat_get_bot_options', array(), $bot_id);
        $options = !empty($bot_options) ? $bot_options : get_option('mxchat_options');
    }

    // Opt-in: when the custom provider is selected for embeddings, route the KB
    // INDEX side through the same custom endpoint the query side uses, so stored
    // vectors and query vectors come from the same model. Default-off behavior
    // below is untouched.
    if (isset($options['custom_provider_for_embeddings']) && $options['custom_provider_for_embeddings'] === 'on') {
        $custom = self::generate_embedding_custom($text, $options);
        // The custom path already returns a human-readable error string —
        // carry it instead of collapsing to null (plan 4a7c0a). The 'custom'
        // branch marker lets the Integrator adapter map the string back onto
        // its own error codes (876edb).
        return is_array($custom) ? $custom : new WP_Error('embedding_failed', (string) $custom, array('branch' => 'custom'));
    }

    $selected_model = $options['embedding_model'] ?? 'text-embedding-ada-002';

    // Determine endpoint and API key based on model
    if (strpos($selected_model, 'voyage') === 0) {
        $endpoint = 'https://api.voyageai.com/v1/embeddings';
        $api_key = $options['voyage_api_key'] ?? '';
    } elseif (strpos($selected_model, 'gemini-embedding') === 0) {
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $selected_model . ':embedContent';
        $api_key = $options['gemini_api_key'] ?? '';
    } else {
        $endpoint = 'https://api.openai.com/v1/embeddings';
        // Prefer the caller-resolved key when one was passed — the query side
        // resolves per-bot keys at its call sites (integrator adapter, 876edb).
        // Index callers pass the preflight key, which equals this options read,
        // so nothing changes for them.
        $api_key = !empty($api_key) ? $api_key : ($options['api_key'] ?? '');
    }
    
    // Prepare request body based on provider
    if (strpos($selected_model, 'gemini-embedding') === 0) {
        // Gemini API format
        $request_body = [
            'model' => 'models/' . $selected_model,
            'content' => [
                'parts' => [
                    ['text' => $text]
                ]
            ],
            'outputDimensionality' => 1536
        ];
        
        // Prepare headers for Gemini (API key as query parameter)
        $endpoint .= '?key=' . $api_key;
        $headers = [
            'Content-Type' => 'application/json'
        ];
    } else {
        // OpenAI/Voyage API format
        $request_body = [
            'input' => $text,
            'model' => $selected_model
        ];
        
        // Add output_dimension for voyage-3-large
        if ($selected_model === 'voyage-3-large') {
            $request_body['output_dimension'] = 2048;
        }
        
        // Prepare headers for OpenAI/Voyage
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ];
    }
    
    $args = [
        'body'        => wp_json_encode($request_body),
        'headers'     => $headers,
        'timeout'     => 60,
        'redirection' => 5,
        'blocking'    => true,
        'httpversion' => '1.0',
        'sslverify'   => true,
    ];
    
    $response = wp_remote_post($endpoint, $args);
    
    if (is_wp_error($response)) {
        $message = 'Embedding request failed (connection): ' . $response->get_error_message();
        if (class_exists('MxChat_Admin')) {
            MxChat_Admin::mxchat_log_debug('embedding_error', $message, array('model' => $selected_model, 'bot_id' => $bot_id));
        }
        return new WP_Error('embedding_failed', $message, array(
            'branch' => 'cloud',
            'kind'   => 'connection',
            'reason' => $response->get_error_message(),
            'model'  => $selected_model,
        ));
    }

    $response_body = json_decode(wp_remote_retrieve_body($response), true);

    // Handle different response formats based on provider
    if (strpos($selected_model, 'gemini-embedding') === 0) {
        // Gemini API response format
        if (isset($response_body['embedding']['values']) && is_array($response_body['embedding']['values'])) {
            self::stamp_active_embedding_model($selected_model);
            return $response_body['embedding']['values'];
        } else {
            return self::embedding_failure_error($response, $selected_model, $api_key, $bot_id);
        }
    } else {
        // OpenAI/Voyage API response format
        if (isset($response_body['data'][0]['embedding']) && is_array($response_body['data'][0]['embedding'])) {
            self::stamp_active_embedding_model($selected_model);
            return $response_body['data'][0]['embedding'];
        } else {
            return self::embedding_failure_error($response, $selected_model, $api_key, $bot_id);
        }
    }
}

/**
 * Build a WP_Error carrying the embedding provider's REAL failure reason,
 * and record it in the Debug Mode log. Previously every failure path
 * returned bare null, so customers saw only "Failed to generate embedding
 * for content" / "Failed to store any chunks" with no cause (plan 4a7c0a).
 *
 * The API key never appears in provider response bodies (it travels in the
 * request headers), but the reason is scrubbed for it anyway before it can
 * reach a notice or the debug log.
 */
private static function embedding_failure_error($response, $selected_model, $api_key, $bot_id) {
    $status  = (int) wp_remote_retrieve_response_code($response);
    $raw     = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($raw, true);

    // Provider error shapes: OpenAI + Gemini use {"error":{"message":…}};
    // Voyage uses {"detail":…}.
    $reason = '';
    if (is_array($decoded)) {
        if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
            $reason = $decoded['error']['message'];
        } elseif (isset($decoded['detail']) && is_string($decoded['detail'])) {
            $reason = $decoded['detail'];
        }
    }
    if ($reason === '') {
        $reason = ($raw !== '') ? substr($raw, 0, 200) : 'empty or malformed response';
    }
    if (is_string($api_key) && $api_key !== '') {
        $reason = str_replace($api_key, '[redacted]', $reason);
    }
    $reason  = substr($reason, 0, 300);
    $message = sprintf('Embedding failed (%s, HTTP %d): %s', $selected_model, $status, $reason);

    if (class_exists('MxChat_Admin')) {
        MxChat_Admin::mxchat_log_debug('embedding_error', $message, array(
            'model'  => $selected_model,
            'status' => $status,
            'bot_id' => $bot_id,
        ));
    }

    // Structured data so the Integrator's query-side adapter can rebuild its
    // typed error contract (auth/rate-limit/quota/invalid-response) without a
    // second transport implementation (876edb). Additive — message unchanged.
    return new WP_Error('embedding_failed', $message, array(
        'branch'     => 'cloud',
        'status'     => $status,
        'error_type' => (is_array($decoded) && isset($decoded['error']['type']) && is_string($decoded['error']['type'])) ? $decoded['error']['type'] : '',
        'reason'     => $reason,
        'model'      => $selected_model,
    ));
}

/**
 * Generate an embedding via a Custom (OpenAI-compatible) provider's /embeddings route.
 * Shared by every embedding entry point so the KNOWLEDGE-BASE INDEX side and the
 * QUERY side route through the same model when the opt-in
 * 'custom_provider_for_embeddings' setting is on. Mirrors the query-path logic in
 * MxChat_Integrator::mxchat_generate_embedding_custom() but takes an explicit
 * $options array so it is callable statically from utils + knowledge-manager.
 *
 * Returns a numeric array (the embedding vector) on success, or a human-readable
 * error string on failure (so callers expecting a string error, like the
 * knowledge-manager, can surface it directly; callers expecting array|null wrap it).
 *
 * @param string $text    Text to embed.
 * @param array  $options The resolved mxchat options (must contain the custom_provider_* keys).
 * @return array|string   Embedding vector on success; error string on failure.
 */
public static function generate_embedding_custom($text, $options) {
    if (empty($text)) {
        return 'No text provided for embedding generation';
    }

    $base_url = isset($options['custom_provider_base_url']) ? rtrim(trim((string) $options['custom_provider_base_url']), '/') : '';
    if (empty($base_url)) {
        return 'Custom provider Base URL is not configured.';
    }

    $api_key     = isset($options['custom_provider_api_key']) ? trim((string) $options['custom_provider_api_key']) : '';
    $auth_scheme = isset($options['custom_provider_auth_scheme']) ? $options['custom_provider_auth_scheme'] : 'bearer';
    $api_version = isset($options['custom_provider_api_version']) ? trim((string) $options['custom_provider_api_version']) : '';

    // Embedding model: shared resolver (dedicated embedding model -> chat model
    // -> 'default') — the mismatch warning's "selected" side reads the same chain.
    $model = self::resolve_custom_embedding_model($options);

    $embed_url = $base_url . '/embeddings';
    if (!empty($api_version)) {
        $embed_url .= (strpos($embed_url, '?') === false ? '?' : '&') . 'api-version=' . rawurlencode($api_version);
    }

    $headers = ['Content-Type' => 'application/json'];
    if (!empty($api_key)) {
        if ($auth_scheme === 'api-key') {
            $headers['api-key'] = $api_key;
        } else {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }
    }

    $response = wp_remote_post($embed_url, [
        'headers' => $headers,
        'body'    => wp_json_encode(['input' => $text, 'model' => $model]),
        'timeout' => 60,
    ]);
    if (is_wp_error($response)) {
        return self::log_custom_embedding_failure(
            'Connection error when generating embeddings (custom provider): ' . $response->get_error_message(),
            $model,
            $api_key
        );
    }

    $status = wp_remote_retrieve_response_code($response);
    $body   = json_decode(wp_remote_retrieve_body($response), true);
    if ($status !== 200) {
        $msg = isset($body['error']['message']) ? $body['error']['message'] : 'HTTP ' . $status;
        return self::log_custom_embedding_failure(
            'Custom embedding endpoint error: ' . $msg,
            $model,
            $api_key,
            (int) $status
        );
    }
    if (isset($body['data'][0]['embedding']) && is_array($body['data'][0]['embedding'])) {
        // Stamp the custom model identity so the active-embedding-model mismatch
        // warning reflects the real (custom) model rather than the built-in setting.
        self::stamp_active_embedding_model('custom:' . $model);
        return $body['data'][0]['embedding'];
    }
    return self::log_custom_embedding_failure('Invalid embedding response from custom provider.', $model, $api_key);
}

/**
 * Record a custom-provider embedding failure in the Debug Mode log, then
 * return the message unchanged so callers keep their string-error contract.
 * The cloud branch has logged its failures since 4a7c0a; the custom branch
 * never did, so chat-side failures on Custom-provider installs were
 * invisible to Debug Mode despite the 3.2.18 readme saying otherwise
 * (plan 71e4b6). Same scrub-then-log shape as embedding_failure_error().
 *
 * @param string $message Human-readable failure (the caller's return value).
 * @param string $model   Resolved custom embedding model.
 * @param string $api_key Scrubbed out of the logged message if it ever appears.
 * @param int    $status  HTTP status when one was received, 0 otherwise.
 * @return string The (scrubbed) message.
 */
private static function log_custom_embedding_failure($message, $model, $api_key, $status = 0) {
    if (is_string($api_key) && $api_key !== '') {
        $message = str_replace($api_key, '[redacted]', $message);
    }

    if (class_exists('MxChat_Admin')) {
        $context = array('model' => 'custom:' . $model);
        if ($status > 0) {
            $context['status'] = $status;
        }
        MxChat_Admin::mxchat_log_debug('embedding_error', $message, $context);
    }

    return $message;
}

/**
 * Submit content as multiple chunks
 *
 * Splits large content into chunks, generates embeddings for each,
 * and stores them with chunk metadata for later reassembly.
 *
 * @param string $content The content to chunk and store
 * @param string $source_url The source URL
 * @param string $api_key The API key for embeddings
 * @param string $bot_id The bot ID
 * @param string $content_type The content type
 * @param MxChat_Chunker $chunker The chunker instance
 * @return bool|WP_Error True on success, WP_Error on failure
 */
private static function submit_chunked_content($content, $source_url, $api_key, $bot_id, $content_type, $chunker) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_system_prompt_content';

    //error_log('[MXCHAT-CHUNK-DEBUG] Starting chunked submission for: ' . $source_url);
    //error_log('[MXCHAT-CHUNK-DEBUG] Content length: ' . strlen($content) . ' chars');

    // URL-less (manual) content needs a minted identity BEFORE chunk ids are derived:
    // every chunk id is md5(source_url)_chunk_N, so with source_url = '' EVERY long
    // manual document shared the md5('') prefix — and the clean-slate delete below
    // wiped the PREVIOUS manual entry's chunks each time a new one was added. The
    // single-vector paths already mint (mxchat:// in WP, manual_* in Pinecone); this
    // was the one storage path that didn't. Keep an mxchat:// identity if the caller
    // already carries one.
    if (strpos($source_url, 'mxchat://') !== 0 && strpos($source_url, 'upload://') !== 0 && !preg_match('#^https?://#i', $source_url)) {
        $source_url = 'mxchat://manual-content/' . time() . '-' . wp_generate_password(8, false);
    }

    // First, delete any existing chunks for this URL (clean slate).
    // Mirror-suspended: this is a re-store, not an entry removal — the Vector
    // Store file is replaced (or kept, on hash match) by the caller's
    // sync_upsert_entry after storage succeeds.
    self::$vectorstore_mirror_suspended = true;
    $delete_result = self::delete_chunks_for_url($source_url, $bot_id);
    self::$vectorstore_mirror_suspended = false;
    if (is_wp_error($delete_result)) {
        //error_log('[MXCHAT-CHUNK-DEBUG] Warning: Failed to delete existing chunks: ' . $delete_result->get_error_message());
        // Continue anyway - we'll overwrite with upsert
    }

    // Split content into chunks
    $chunks = $chunker->chunk_text($content);
    $total_chunks = count($chunks);

    //error_log('[MXCHAT-CHUNK-DEBUG] Created ' . $total_chunks . ' chunks');
    foreach ($chunks as $i => $chunk) {
        //error_log('[MXCHAT-CHUNK-DEBUG] Chunk ' . $i . ' length: ' . strlen($chunk) . ' chars, preview: ' . substr($chunk, 0, 100));
    }

    //error_log('[MXCHAT-CHUNK] Split content into ' . $total_chunks . ' chunks');

    if ($total_chunks === 0) {
        return new WP_Error('chunking_failed', 'Content could not be split into chunks');
    }

    $errors = array();
    $embed_failures     = 0;
    $first_embed_reason = '';
    $first_store_reason = '';
    $is_pinecone = self::is_pinecone_enabled_for_bot($bot_id);

    foreach ($chunks as $index => $chunk_text) {
        // Generate chunk metadata
        $chunk_metadata = MxChat_Chunker::create_chunk_metadata($index, $total_chunks, $source_url);

        // AI-Engine-style aliases so external consumers (Pinecone/Qdrant/Chroma) can rely on
        // a stable shorthand ('source'/'part_index'/'part_total') without parsing our internal names.
        $chunk_metadata['source']     = $source_url;
        $chunk_metadata['part_index'] = (int) $index;
        $chunk_metadata['part_total'] = (int) $total_chunks;

        /**
         * Filter the per-chunk metadata blob before it's written to the KB store.
         *
         * @param array  $chunk_metadata  Metadata array (source, part_index, part_total, chunk_index, total_chunks, source_url, parent_url_hash, document_type, ...).
         * @param string $chunk_text      The chunk text being stored.
         * @param array  $context         ['bot_id' => string, 'content_type' => string, 'source_url' => string, 'part_index' => int, 'part_total' => int]
         * @return array Updated metadata array.
         */
        $chunk_metadata = apply_filters(
            'mxchat_embedding_chunk_metadata',
            $chunk_metadata,
            $chunk_text,
            array(
                'bot_id'       => $bot_id,
                'content_type' => $content_type,
                'source_url'   => $source_url,
                'part_index'   => (int) $index,
                'part_total'   => (int) $total_chunks,
            )
        );

        $chunk_vector_id = MxChat_Chunker::generate_chunk_vector_id($source_url, $index);

        //error_log('[MXCHAT-CHUNK] Processing chunk ' . ($index + 1) . '/' . $total_chunks . ' (ID: ' . $chunk_vector_id . ')');

        // Generate embedding for this chunk
        $embedding_vector = self::generate_embedding($chunk_text, $api_key, $bot_id);

        if (!is_array($embedding_vector)) {
            // Track embedding failures separately from storage failures, and
            // keep the first provider reason seen — the two failure classes
            // have opposite remedies (API key vs Pinecone/DB) (plan 4a7c0a).
            $embed_failures++;
            $reason = is_wp_error($embedding_vector) ? $embedding_vector->get_error_message() : '';
            if ($reason !== '' && $first_embed_reason === '') {
                $first_embed_reason = $reason;
            }
            $errors[] = new WP_Error('embedding_failed', 'Failed to generate embedding for chunk ' . $index . ($reason !== '' ? ' — ' . $reason : ''));
            continue;
        }

        if ($is_pinecone) {
            // Store in Pinecone with chunk metadata
            $result = self::store_chunk_in_pinecone(
                $embedding_vector,
                $chunk_text,
                $source_url,
                $chunk_vector_id,
                $bot_id,
                $content_type,
                $chunk_metadata
            );
        } else {
            // Store in WordPress DB with chunk metadata
            $content_with_metadata = MxChat_Chunker::format_chunk_for_storage($chunk_text, $chunk_metadata);
            $embedding_vector_serialized = maybe_serialize($embedding_vector);

            $result = self::store_chunk_in_wordpress_db(
                $content_with_metadata,
                $source_url,
                $embedding_vector_serialized,
                $table_name,
                $content_type,
                $chunk_metadata
            );
        }

        if (is_wp_error($result)) {
            $errors[] = $result;
            if ($first_store_reason === '') {
                $first_store_reason = $result->get_error_message();
            }
        }
    }

    if (count($errors) === $total_chunks) {
        // Say WHICH stage failed — "failed to store" used to cover pure
        // embedding failures too, sending customers to debug Pinecone when
        // the problem was their embedding API key (plan 4a7c0a).
        if ($embed_failures === $total_chunks) {
            return new WP_Error('chunking_failed',
                'Failed to store any chunks — every chunk failed to embed'
                . ($first_embed_reason !== '' ? ': ' . $first_embed_reason : '')
                . ' Check the embedding provider API key and model under MxChat Settings.');
        }
        if ($embed_failures === 0) {
            return new WP_Error('chunking_failed',
                'Failed to store any chunks — embeddings generated but storage failed'
                . ($first_store_reason !== '' ? ': ' . $first_store_reason : '')
                . ' Check the knowledge base storage (Pinecone index or database).');
        }
        return new WP_Error('chunking_failed', sprintf(
            'Failed to store any chunks — %d failed to embed%s and %d failed to store%s',
            $embed_failures,
            $first_embed_reason !== '' ? ' (' . $first_embed_reason . ')' : '',
            $total_chunks - $embed_failures,
            $first_store_reason !== '' ? ' (' . $first_store_reason . ')' : ''
        ));
    }

    if (!empty($errors)) {
        $detail = $first_embed_reason !== '' ? $first_embed_reason : $first_store_reason;
        return new WP_Error('chunking_partial_failure',
            sprintf('Failed to store %d of %d chunks', count($errors), $total_chunks)
            . ($detail !== '' ? ' — first error: ' . $detail : ''));
    }

    //error_log('[MXCHAT-CHUNK] Successfully stored all ' . $total_chunks . ' chunks');
    return true;
}

/**
 * Store a single chunk in Pinecone with chunk-specific metadata
 */
private static function store_chunk_in_pinecone($embedding_vector, $chunk_text, $source_url, $vector_id, $bot_id, $content_type, $chunk_metadata) {
    // Get Pinecone configuration
    if ($bot_id === 'default' || !class_exists('MxChat_Multi_Bot_Manager')) {
        $pinecone_options = get_option('mxchat_pinecone_addon_options');
        $api_key = $pinecone_options['mxchat_pinecone_api_key'] ?? '';
        $host = $pinecone_options['mxchat_pinecone_host'] ?? '';
        $namespace = $pinecone_options['mxchat_pinecone_namespace'] ?? '';
    } else {
        $bot_pinecone_config = apply_filters('mxchat_get_bot_pinecone_config', array(), $bot_id);
        if (empty($bot_pinecone_config)) {
            $pinecone_options = get_option('mxchat_pinecone_addon_options');
            $api_key = $pinecone_options['mxchat_pinecone_api_key'] ?? '';
            $host = $pinecone_options['mxchat_pinecone_host'] ?? '';
            $namespace = $pinecone_options['mxchat_pinecone_namespace'] ?? '';
        } else {
            $api_key = $bot_pinecone_config['api_key'] ?? '';
            $host = $bot_pinecone_config['host'] ?? '';
            $namespace = $bot_pinecone_config['namespace'] ?? '';
        }
    }

    if (empty($host) || empty($api_key)) {
        return new WP_Error('pinecone_config', 'Pinecone is not properly configured');
    }

    $api_endpoint = "https://{$host}/vectors/upsert";

    // Build metadata with chunk information
    $metadata = array(
        'text' => $chunk_text,
        'source_url' => $source_url,
        'type' => $content_type,
        'is_chunked' => true,
        'chunk_index' => $chunk_metadata['chunk_index'],
        'total_chunks' => $chunk_metadata['total_chunks'],
        'parent_url_hash' => $chunk_metadata['parent_url_hash'],
        'last_updated' => time(),
        'created_at' => time(),
        'bot_id' => $bot_id,
    );

    $vector_data = array(
        'id' => $vector_id,
        'values' => $embedding_vector,
        'metadata' => $metadata
    );

    $request_body = array(
        'vectors' => array($vector_data)
    );

    if (!empty($namespace)) {
        $request_body['namespace'] = $namespace;
    }

    $response = wp_remote_post($api_endpoint, array(
        'headers' => array(
            'Api-Key' => $api_key,
            'accept' => 'application/json',
            'content-type' => 'application/json'
        ),
        'body' => wp_json_encode($request_body),
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        return $response;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        return new WP_Error('pinecone_api', 'Pinecone API error: HTTP ' . $response_code);
    }

    return true;
}

/**
 * Store a single chunk in WordPress database
 */
private static function store_chunk_in_wordpress_db($content_with_metadata, $source_url, $embedding_vector_serialized, $table_name, $content_type, $chunk_metadata) {
    global $wpdb;

    // For chunks, we always insert new rows (no duplicate checking)
    // The URL includes chunk info in the metadata, but source_url stays the same for grouping
    $result = $wpdb->insert(
        $table_name,
        array(
            'url' => $source_url,
            'article_content' => $content_with_metadata,
            'embedding_vector' => $embedding_vector_serialized,
            'source_url' => $source_url,
            'content_type' => $content_type,
            'timestamp' => current_time('mysql')
        ),
        array('%s', '%s', '%s', '%s', '%s', '%s')
    );

    if ($result === false) {
        return new WP_Error('database_failed', 'Failed to insert chunk: ' . $wpdb->last_error);
    }

    return true;
}

/**
 * Delete all chunks for a given URL
 *
 * @param string $source_url The source URL
 * @param string $bot_id The bot ID
 * @return bool|WP_Error True on success, WP_Error on failure
 */
public static function delete_chunks_for_url($source_url, $bot_id = 'default') {
    //error_log('[MXCHAT-CHUNK-DELETE] Deleting chunks for URL: ' . $source_url);

    // Entry removal — mirror it to the Vector Store (unless a storage routine
    // is mid-re-store, see $vectorstore_mirror_suspended).
    if (!self::$vectorstore_mirror_suspended && class_exists('MxChat_Vectorstore_Manager')) {
        MxChat_Vectorstore_Manager::sync_delete_entry($source_url, $bot_id);
    }

    if (self::is_pinecone_enabled_for_bot($bot_id)) {
        return self::delete_pinecone_chunks_by_url($source_url, $bot_id);
    } else {
        return self::delete_wordpress_chunks_by_url($source_url);
    }
}

/**
 * Delete all chunks for a URL from Pinecone
 */
/**
 * Delete leftover md5(url)_chunk_N vectors after a URL's content was re-stored
 * as a SINGLE vector (content shrank below the chunk threshold on edit/re-import).
 * Unlike delete_pinecone_chunks_by_url this leaves the base id alone — the caller
 * just upserted the new content there. Failure is logged, not fatal: the save
 * itself succeeded, and the next save retries the sweep.
 */
private static function cleanup_pinecone_chunk_stragglers($source_url, $bot_id) {
    if ($bot_id === 'default' || !class_exists('MxChat_Multi_Bot_Manager')) {
        $pinecone_options = get_option('mxchat_pinecone_addon_options');
        $api_key = $pinecone_options['mxchat_pinecone_api_key'] ?? '';
        $host = $pinecone_options['mxchat_pinecone_host'] ?? '';
        $namespace = $pinecone_options['mxchat_pinecone_namespace'] ?? '';
    } else {
        $bot_pinecone_config = apply_filters('mxchat_get_bot_pinecone_config', array(), $bot_id);
        if (empty($bot_pinecone_config)) {
            $pinecone_options = get_option('mxchat_pinecone_addon_options');
            $api_key = $pinecone_options['mxchat_pinecone_api_key'] ?? '';
            $host = $pinecone_options['mxchat_pinecone_host'] ?? '';
            $namespace = $pinecone_options['mxchat_pinecone_namespace'] ?? '';
        } else {
            $api_key = $bot_pinecone_config['api_key'] ?? '';
            $host = $bot_pinecone_config['host'] ?? '';
            $namespace = $bot_pinecone_config['namespace'] ?? '';
        }
    }

    if (empty($host) || empty($api_key)) {
        return;
    }

    $stragglers = array();

    // Pinecone /vectors/list is a GET endpoint with query-string parameters (a POST
    // answers 200-with-an-empty-body, which reads as "no stragglers").
    $query_params = array(
        'prefix' => md5($source_url) . '_chunk_',
        'limit' => 100,
    );
    if (!empty($namespace)) {
        $query_params['namespace'] = $namespace;
    }

    $list_url = "https://{$host}/vectors/list?" . http_build_query($query_params);

    do {
        $list_response = wp_remote_get($list_url, array(
            'headers' => array(
                'Api-Key' => $api_key,
                'accept' => 'application/json',
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($list_response) || wp_remote_retrieve_response_code($list_response) !== 200) {
            break;
        }

        $list_data = json_decode(wp_remote_retrieve_body($list_response), true);
        if (!empty($list_data['vectors'])) {
            foreach ($list_data['vectors'] as $vector) {
                if (isset($vector['id'])) {
                    $stragglers[] = $vector['id'];
                }
            }
        }

        $next_token = $list_data['pagination']['next'] ?? '';
        if (empty($next_token)) {
            break;
        }

        $query_params['paginationToken'] = $next_token;
        $list_url = "https://{$host}/vectors/list?" . http_build_query($query_params);
    } while (true);

    if (empty($stragglers)) {
        return;
    }

    $delete_body = array('ids' => $stragglers);
    if (!empty($namespace)) {
        $delete_body['namespace'] = $namespace;
    }

    $delete_response = wp_remote_post("https://{$host}/vectors/delete", array(
        'headers' => array(
            'Api-Key' => $api_key,
            'accept' => 'application/json',
            'content-type' => 'application/json'
        ),
        'body' => wp_json_encode($delete_body),
        'timeout' => 30
    ));

    if ((is_wp_error($delete_response) || wp_remote_retrieve_response_code($delete_response) !== 200)
        && class_exists('MxChat_Admin') && method_exists('MxChat_Admin', 'mxchat_log_debug')) {
        MxChat_Admin::mxchat_log_debug('pinecone_error', 'Failed to sweep stale chunk vectors after single-vector re-store', array('source_url' => $source_url, 'bot_id' => $bot_id, 'count' => count($stragglers)));
    }
}

private static function delete_pinecone_chunks_by_url($source_url, $bot_id) {
    // Get Pinecone configuration
    if ($bot_id === 'default' || !class_exists('MxChat_Multi_Bot_Manager')) {
        $pinecone_options = get_option('mxchat_pinecone_addon_options');
        $api_key = $pinecone_options['mxchat_pinecone_api_key'] ?? '';
        $host = $pinecone_options['mxchat_pinecone_host'] ?? '';
        $namespace = $pinecone_options['mxchat_pinecone_namespace'] ?? '';
    } else {
        $bot_pinecone_config = apply_filters('mxchat_get_bot_pinecone_config', array(), $bot_id);
        if (empty($bot_pinecone_config)) {
            $pinecone_options = get_option('mxchat_pinecone_addon_options');
            $api_key = $pinecone_options['mxchat_pinecone_api_key'] ?? '';
            $host = $pinecone_options['mxchat_pinecone_host'] ?? '';
            $namespace = $pinecone_options['mxchat_pinecone_namespace'] ?? '';
        } else {
            $api_key = $bot_pinecone_config['api_key'] ?? '';
            $host = $bot_pinecone_config['host'] ?? '';
            $namespace = $bot_pinecone_config['namespace'] ?? '';
        }
    }

    if (empty($host) || empty($api_key)) {
        return new WP_Error('pinecone_config', 'Pinecone is not properly configured');
    }

    $base_vector_id = md5($source_url);
    $vectors_to_delete = array();

    // Add the original single-vector ID (for non-chunked content)
    $vectors_to_delete[] = $base_vector_id;

    // Pinecone /vectors/list is a GET endpoint with query-string parameters; a POST here returns a
    // non-200 silently and we end up only deleting the base vector, leaving chunks orphaned.
    $query_params = array(
        'prefix' => $base_vector_id . '_chunk_',
        'limit' => 100,
    );
    if (!empty($namespace)) {
        $query_params['namespace'] = $namespace;
    }

    $list_url = "https://{$host}/vectors/list?" . http_build_query($query_params);

    // Paginate in case a URL has more than 100 chunks.
    do {
        $list_response = wp_remote_get($list_url, array(
            'headers' => array(
                'Api-Key' => $api_key,
                'accept' => 'application/json',
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($list_response) || wp_remote_retrieve_response_code($list_response) !== 200) {
            break;
        }

        $list_data = json_decode(wp_remote_retrieve_body($list_response), true);
        if (!empty($list_data['vectors'])) {
            foreach ($list_data['vectors'] as $vector) {
                if (isset($vector['id'])) {
                    $vectors_to_delete[] = $vector['id'];
                }
            }
        }

        $next_token = $list_data['pagination']['next'] ?? '';
        if (empty($next_token)) {
            break;
        }

        $query_params['paginationToken'] = $next_token;
        $list_url = "https://{$host}/vectors/list?" . http_build_query($query_params);
    } while (true);

    if (empty($vectors_to_delete)) {
        //error_log('[MXCHAT-CHUNK-DELETE] No vectors found to delete');
        return true;
    }

    //error_log('[MXCHAT-CHUNK-DELETE] Deleting ' . count($vectors_to_delete) . ' vectors from Pinecone');

    // Delete vectors
    $delete_url = "https://{$host}/vectors/delete";

    $delete_body = array(
        'ids' => $vectors_to_delete
    );

    if (!empty($namespace)) {
        $delete_body['namespace'] = $namespace;
    }

    $delete_response = wp_remote_post($delete_url, array(
        'headers' => array(
            'Api-Key' => $api_key,
            'accept' => 'application/json',
            'content-type' => 'application/json'
        ),
        'body' => wp_json_encode($delete_body),
        'timeout' => 30
    ));

    if (is_wp_error($delete_response)) {
        return $delete_response;
    }

    $response_code = wp_remote_retrieve_response_code($delete_response);
    if ($response_code !== 200) {
        return new WP_Error('pinecone_delete', 'Failed to delete vectors: HTTP ' . $response_code);
    }

    return true;
}

/**
 * Delete all chunks for a URL from WordPress database
 */
private static function delete_wordpress_chunks_by_url($source_url) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'mxchat_system_prompt_content';

    // Delete all rows with this source_url (handles both chunked and non-chunked)
    $result = $wpdb->delete(
        $table_name,
        array('source_url' => $source_url),
        array('%s')
    );

    if ($result === false) {
        return new WP_Error('database_delete', 'Failed to delete chunks: ' . $wpdb->last_error);
    }

    //error_log('[MXCHAT-CHUNK-DELETE] Deleted ' . $result . ' rows from WordPress DB');
    return true;
}

/**
 * Hybrid keyword boost (plan-38ffa1): detect whether the WP-DB knowledge
 * table can serve the keyword leg via a MySQL FULLTEXT index, creating the
 * index if needed. Detection runs once and caches the answer in the
 * mxchat_hybrid_keyword_capability option ('fulltext' | 'like'); pass
 * $force to re-detect. LIKE is the graceful fallback for shared hosts
 * whose ALTER fails — the feature works either way, FULLTEXT just ranks
 * better and scales.
 *
 * @param  bool $force Re-run detection even if a cached answer exists.
 * @return string 'fulltext' or 'like'
 */
public static function mxchat_hybrid_detect_capability($force = false) {
    $cached = get_option('mxchat_hybrid_keyword_capability', '');
    if (!$force && in_array($cached, array('fulltext', 'like'), true)) {
        return $cached;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'mxchat_system_prompt_content';

    $index_exists = $wpdb->get_var("SHOW INDEX FROM {$table} WHERE Key_name = 'mxchat_content_ft'");
    if (!$index_exists) {
        // Suppress the visible error on hosts where this is not permitted —
        // failure is an expected, handled outcome (LIKE fallback).
        $suppress = $wpdb->suppress_errors(true);
        $wpdb->query("ALTER TABLE {$table} ADD FULLTEXT INDEX mxchat_content_ft (article_content)");
        $wpdb->suppress_errors($suppress);
        $index_exists = $wpdb->get_var("SHOW INDEX FROM {$table} WHERE Key_name = 'mxchat_content_ft'");
    }

    $capability = $index_exists ? 'fulltext' : 'like';
    update_option('mxchat_hybrid_keyword_capability', $capability);
    return $capability;
}

/**
 * Public entry for re-embedding already-stored content in place (wp mxchat
 * rtl-repair, plan d1e6f7). Thin wrapper so the repair CLI gets the exact
 * provider routing the import path uses — the repaired vector must come from
 * the same model family the bot indexes with, or retrieval stays broken.
 */
public static function regenerate_embedding($text, $api_key, $bot_id = 'default') {
    return self::generate_embedding($text, $api_key, $bot_id);
}

/**
 * Restore logical character order in PDF-extracted RTL text (plan 32bf9e).
 *
 * The bundled Smalot parser only un-reverses text runs tagged with the
 * ReversedChars marked-content operator (Word emits it; LibreOffice and most
 * other producers do not), so their Hebrew/Arabic PDFs extract in visual
 * (reversed) order and embed/search as garbage. This is OUR post-processing
 * seam over getText() — the parser itself is never patched (it gets replaced
 * wholesale on library updates).
 *
 * Heuristic and deliberately conservative, per line:
 * - lines without strong RTL codepoints are untouched (a fully-Latin line in
 *   an RTL document therefore stays as extracted — accepted limitation);
 * - Arabic presentation forms are a definitive visual-order signal (they only
 *   appear in shaped output): de-shape to base letters and reverse;
 * - otherwise flip only on positive evidence — Hebrew final-letter position
 *   (a sofit at word START only happens in reversed text) or sentence
 *   punctuation position (leading in visual order, trailing in logical);
 * - ambiguous lines are left alone: a conservative miss beats corrupting a
 *   Word-produced extraction the parser already handled (the double-flip
 *   guard this plan's approval named mandatory).
 *
 * @param string $text    One extracted page string, straight from getText().
 * @param string $context Caller tag for the Debug Mode entry (site + page).
 * @return string Text with RTL lines restored to logical order.
 */
public static function normalize_pdf_rtl($text, $context = '') {
    if (!is_string($text) || '' === $text) {
        return $text;
    }
    // Escape hatch for sites whose PDFs already extract logically.
    if (!apply_filters('mxchat_pdf_rtl_normalize', true, $text)) {
        return $text;
    }
    // Fast bail: nothing RTL anywhere in the page.
    if (!preg_match('/[\x{0590}-\x{05FF}\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text)) {
        return $text;
    }

    $parts = preg_split('/(\R)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (false === $parts) {
        return $text;
    }

    $flipped_lines  = 0;
    $deshaped_lines = 0;
    foreach ($parts as $i => $part) {
        if ('' === $part || preg_match('/^\R$/u', $part)) {
            continue;
        }
        $was_flipped  = false;
        $was_deshaped = false;
        $new = self::pdf_rtl_normalize_line($part, $was_flipped, $was_deshaped);
        if ($new !== $part) {
            $parts[$i] = $new;
        }
        if ($was_flipped) {
            $flipped_lines++;
        }
        if ($was_deshaped) {
            $deshaped_lines++;
        }
    }

    if (($flipped_lines || $deshaped_lines) && class_exists('MxChat_Admin')) {
        MxChat_Admin::mxchat_log_debug('pdf_rtl_normalized', 'RTL PDF text restored to logical order', array(
            'context'        => (string) $context,
            'lines_flipped'  => $flipped_lines,
            'lines_deshaped' => $deshaped_lines,
            'decision'       => 'visual-order extraction detected',
        ));
    }

    return implode('', $parts);
}

/**
 * Normalize one line. Sets $flipped/$deshaped for the caller's debug entry.
 */
private static function pdf_rtl_normalize_line($line, &$flipped, &$deshaped) {
    $flipped  = false;
    $deshaped = false;

    if (!preg_match('/[\x{0590}-\x{05FF}\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $line)) {
        return $line;
    }

    $has_forms = (bool) preg_match('/[\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $line);
    $work = $line;
    if ($has_forms) {
        $work = strtr($work, self::pdf_rtl_deshape_map());
        $deshaped = ($work !== $line);
    }

    $verdict = 'ambiguous';
    if ($has_forms) {
        // Shaped glyph codepoints only exist in visual-order output.
        $verdict = 'visual';
    } else {
        // Strong-direction dominance gate first: an LTR-dominant line with an
        // embedded RTL word is not flip material.
        $rtl_count = preg_match_all('/[\x{0590}-\x{05FF}\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u', $work, $m_rtl);
        $ltr_count = preg_match_all('/[A-Za-z]/u', $work, $m_ltr);
        if ($rtl_count < 1 || $rtl_count <= $ltr_count) {
            return $line;
        }

        // Hebrew final letters (ך ם ן ף ץ) end words in logical text; one at
        // a word START (Hebrew letter follows, none precedes) is reversal
        // evidence. Positional, so it survives the line being reversed.
        $sofit_initial  = preg_match_all('/(?<![\x{05D0}-\x{05EA}])[\x{05DA}\x{05DD}\x{05DF}\x{05E3}\x{05E5}](?=[\x{05D0}-\x{05EA}])/u', $work, $m_i);
        $sofit_terminal = preg_match_all('/(?<=[\x{05D0}-\x{05EA}])[\x{05DA}\x{05DD}\x{05DF}\x{05E3}\x{05E5}](?![\x{05D0}-\x{05EA}])/u', $work, $m_t);
        if ($sofit_initial > $sofit_terminal) {
            $verdict = 'visual';
        } elseif ($sofit_terminal > $sofit_initial) {
            $verdict = 'logical';
        } else {
            // Sentence punctuation lands at the visual LEFT edge of an RTL
            // line, i.e. the START of a visual-order extraction.
            $trimmed = trim($work);
            $starts_punct = (bool) preg_match('/^[.?!:;,]/u', $trimmed);
            $ends_punct   = (bool) preg_match('/[.?!:;,]$/u', $trimmed);
            if ($starts_punct && !$ends_punct) {
                $verdict = 'visual';
            } elseif ($ends_punct && !$starts_punct) {
                $verdict = 'logical';
            }
        }
    }

    if ('visual' !== $verdict) {
        // Ambiguous or logical: hand back the original line UNLESS we
        // de-shaped (de-shaping alone is always safe — same letters, same
        // order, un-ligated).
        return $deshaped ? $work : $line;
    }

    $flipped = true;
    return self::pdf_rtl_flip_line($work);
}

/**
 * Reverse a visual-order line into logical order: full character reversal,
 * mirror paired punctuation, then re-reverse embedded LTR runs (Latin words
 * and digit sequences, incl. Arabic-Indic digits) so they stay readable.
 */
private static function pdf_rtl_flip_line($line) {
    $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
    if (false === $chars) {
        return $line;
    }
    $reversed = implode('', array_reverse($chars));
    $reversed = strtr($reversed, array(
        '(' => ')', ')' => '(',
        '[' => ']', ']' => '[',
        '{' => '}', '}' => '{',
        '<' => '>', '>' => '<',
    ));
    $restored = preg_replace_callback(
        '/[0-9A-Za-z\x{0660}-\x{0669}\x{06F0}-\x{06F9}](?:[0-9A-Za-z\x{0660}-\x{0669}\x{06F0}-\x{06F9} .,\'"%\-:\/]*[0-9A-Za-z\x{0660}-\x{0669}\x{06F0}-\x{06F9}])?/u',
        function ($m) {
            $run = preg_split('//u', $m[0], -1, PREG_SPLIT_NO_EMPTY);
            return false === $run ? $m[0] : implode('', array_reverse($run));
        },
        $reversed
    );
    return null === $restored ? $reversed : $restored;
}

/**
 * Arabic presentation forms (A + B) -> base letters. Built once from range
 * specs rather than ~120 hand-written literal entries; every codepoint in a
 * range maps to the same base sequence (isolated/final/initial/medial forms
 * of one letter are contiguous in the FE70 block).
 */
private static function pdf_rtl_deshape_map() {
    static $map = null;
    if (null !== $map) {
        return $map;
    }
    $ranges = array(
        // Form B harakat (each pair = standalone + tatweel-joined form).
        array(0xFE70, 0xFE71, array(0x064B)), array(0xFE72, 0xFE72, array(0x064C)),
        array(0xFE74, 0xFE74, array(0x064D)), array(0xFE76, 0xFE77, array(0x064E)),
        array(0xFE78, 0xFE79, array(0x064F)), array(0xFE7A, 0xFE7B, array(0x0650)),
        array(0xFE7C, 0xFE7D, array(0x0651)), array(0xFE7E, 0xFE7F, array(0x0652)),
        // Form B letters.
        array(0xFE80, 0xFE80, array(0x0621)), array(0xFE81, 0xFE82, array(0x0622)),
        array(0xFE83, 0xFE84, array(0x0623)), array(0xFE85, 0xFE86, array(0x0624)),
        array(0xFE87, 0xFE88, array(0x0625)), array(0xFE89, 0xFE8C, array(0x0626)),
        array(0xFE8D, 0xFE8E, array(0x0627)), array(0xFE8F, 0xFE92, array(0x0628)),
        array(0xFE93, 0xFE94, array(0x0629)), array(0xFE95, 0xFE98, array(0x062A)),
        array(0xFE99, 0xFE9C, array(0x062B)), array(0xFE9D, 0xFEA0, array(0x062C)),
        array(0xFEA1, 0xFEA4, array(0x062D)), array(0xFEA5, 0xFEA8, array(0x062E)),
        array(0xFEA9, 0xFEAA, array(0x062F)), array(0xFEAB, 0xFEAC, array(0x0630)),
        array(0xFEAD, 0xFEAE, array(0x0631)), array(0xFEAF, 0xFEB0, array(0x0632)),
        array(0xFEB1, 0xFEB4, array(0x0633)), array(0xFEB5, 0xFEB8, array(0x0634)),
        array(0xFEB9, 0xFEBC, array(0x0635)), array(0xFEBD, 0xFEC0, array(0x0636)),
        array(0xFEC1, 0xFEC4, array(0x0637)), array(0xFEC5, 0xFEC8, array(0x0638)),
        array(0xFEC9, 0xFECC, array(0x0639)), array(0xFECD, 0xFED0, array(0x063A)),
        array(0xFED1, 0xFED4, array(0x0641)), array(0xFED5, 0xFED8, array(0x0642)),
        array(0xFED9, 0xFEDC, array(0x0643)), array(0xFEDD, 0xFEE0, array(0x0644)),
        array(0xFEE1, 0xFEE4, array(0x0645)), array(0xFEE5, 0xFEE8, array(0x0646)),
        array(0xFEE9, 0xFEEC, array(0x0647)), array(0xFEED, 0xFEEE, array(0x0648)),
        array(0xFEEF, 0xFEF0, array(0x0649)), array(0xFEF1, 0xFEF4, array(0x064A)),
        // Form B lam-alef ligatures decompose to two letters.
        array(0xFEF5, 0xFEF6, array(0x0644, 0x0622)), array(0xFEF7, 0xFEF8, array(0x0644, 0x0623)),
        array(0xFEF9, 0xFEFA, array(0x0644, 0x0625)), array(0xFEFB, 0xFEFC, array(0x0644, 0x0627)),
        // Form A: Persian / Urdu letters in common use.
        array(0xFB56, 0xFB59, array(0x067E)), array(0xFB66, 0xFB69, array(0x0679)),
        array(0xFB7A, 0xFB7D, array(0x0686)), array(0xFB88, 0xFB89, array(0x0688)),
        array(0xFB8A, 0xFB8B, array(0x0698)), array(0xFB8E, 0xFB91, array(0x06A9)),
        array(0xFB92, 0xFB95, array(0x06AF)), array(0xFBA6, 0xFBA9, array(0x06C1)),
        array(0xFBAA, 0xFBAD, array(0x06BE)), array(0xFBAE, 0xFBAF, array(0x06D2)),
        array(0xFBFC, 0xFBFF, array(0x06CC)),
    );
    $map = array();
    foreach ($ranges as $range) {
        $base = '';
        foreach ($range[2] as $cp) {
            $base .= self::pdf_rtl_cp_to_utf8($cp);
        }
        for ($cp = $range[0]; $cp <= $range[1]; $cp++) {
            $map[self::pdf_rtl_cp_to_utf8($cp)] = $base;
        }
    }
    return $map;
}

/**
 * Codepoint to UTF-8 without ext-intl / mbstring entity tricks (PHP 7.2 floor).
 */
private static function pdf_rtl_cp_to_utf8($cp) {
    if ($cp < 0x80) {
        return chr($cp);
    }
    if ($cp < 0x800) {
        return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
    }
    if ($cp < 0x10000) {
        return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }
    return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
}
}