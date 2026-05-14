<?php
/**
 * MxChat REST API
 *
 * Bearer-token-authenticated REST endpoints exposing core primitives:
 *
 *   GET    /wp-json/mxchat/v1/transcripts   — read chat transcripts (filterable)
 *   DELETE /wp-json/mxchat/v1/transcripts   — delete by session_ids (cascades)
 *   POST   /wp-json/mxchat/v1/knowledge     — push content into the knowledge base
 *   GET    /wp-json/mxchat/v1/health        — connectivity + capability check
 *
 * These are general-purpose primitives. They power the official MxChat FAQ
 * agent, but anyone running MxChat can use them for analytics exports,
 * external automations (n8n, Zapier, Make), data migrations, custom RAG
 * pipelines, etc.
 *
 * Auth:
 *   Bearer token stored in wp_options under `mxchat_api_token`.
 *   - When the token is empty (default), all authenticated routes are
 *     locked. The endpoints simply refuse with 401, so the surface area
 *     is zero until the site owner explicitly enables it from the
 *     "MxChat → API Access" admin page (or via WP-CLI).
 *   - Comparison uses hash_equals() for constant-time safety.
 *
 * Privacy:
 *   The /transcripts endpoint returns user-submitted chat data. It is
 *   intentionally gated behind a token the site owner generates. No data
 *   leaves the site unsolicited.
 *
 * @package MxChat
 * @since   3.2.5
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Rest_Api {

    const REST_NAMESPACE = 'mxchat/v1';
    const TOKEN_OPTION   = 'mxchat_api_token';

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register the REST routes.
     */
    public function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/health', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'handle_health'),
                'permission_callback' => '__return_true',
            ),
        ));

        register_rest_route(self::REST_NAMESPACE, '/transcripts', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'handle_get_transcripts'),
                'permission_callback' => array($this, 'check_bearer_token'),
                'args'                => array(
                    'since' => array(
                        'description'       => __('Only return rows with timestamp >= this value. Accepts ISO 8601 (2026-05-07T00:00:00Z) or any strtotime-compatible string.', 'mxchat'),
                        'type'              => 'string',
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'until' => array(
                        'description'       => __('Only return rows with timestamp <= this value. Same format as `since`.', 'mxchat'),
                        'type'              => 'string',
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'session_id' => array(
                        'description'       => __('Filter to a single conversation.', 'mxchat'),
                        'type'              => 'string',
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'role' => array(
                        'description'       => __('Filter by role. One of: user, assistant, all (default: all).', 'mxchat'),
                        'type'              => 'string',
                        'required'          => false,
                        'enum'              => array('user', 'assistant', 'all'),
                        'default'           => 'all',
                    ),
                    'has_rag_context' => array(
                        'description'       => __('Filter by whether the row has retrieved RAG context. Useful for finding "no-knowledge-hit" answers. One of: yes, no, any (default: any).', 'mxchat'),
                        'type'              => 'string',
                        'required'          => false,
                        'enum'              => array('yes', 'no', 'any'),
                        'default'           => 'any',
                    ),
                    'limit' => array(
                        'description'       => __('Max number of rows to return. 1-1000, default 100.', 'mxchat'),
                        'type'              => 'integer',
                        'required'          => false,
                        'default'           => 100,
                        'minimum'           => 1,
                        'maximum'           => 1000,
                    ),
                    'offset' => array(
                        'description'       => __('Skip this many rows (for pagination).', 'mxchat'),
                        'type'              => 'integer',
                        'required'          => false,
                        'default'           => 0,
                        'minimum'           => 0,
                    ),
                    'order' => array(
                        'description'       => __('Sort order on timestamp. asc or desc (default: desc).', 'mxchat'),
                        'type'              => 'string',
                        'required'          => false,
                        'enum'              => array('asc', 'desc'),
                        'default'           => 'desc',
                    ),
                ),
            ),
        ));

        register_rest_route(self::REST_NAMESPACE, '/transcripts', array(
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array($this, 'handle_delete_transcripts'),
                'permission_callback' => array($this, 'check_bearer_token'),
                'args'                => array(
                    'session_ids' => array(
                        'description' => __('Array of session_ids to delete. Required and non-empty — there is intentionally no "delete all" shorthand.', 'mxchat'),
                        'type'        => 'array',
                        'required'    => true,
                        'items'       => array('type' => 'string'),
                    ),
                    'cascade' => array(
                        'description' => __('If true (default), also deletes related rows in mxchat_transcript_translations and mxchat_url_clicks for each session_id.', 'mxchat'),
                        'type'        => 'boolean',
                        'required'    => false,
                        'default'     => true,
                    ),
                ),
            ),
        ));

        register_rest_route(self::REST_NAMESPACE, '/knowledge', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array($this, 'handle_post_knowledge'),
                'permission_callback' => array($this, 'check_bearer_token'),
                'args'                => array(
                    'content' => array(
                        'description' => __('The content body to embed and store.', 'mxchat'),
                        'type'        => 'string',
                        'required'    => true,
                    ),
                    'source_url' => array(
                        'description'       => __('Canonical URL the content came from. Used as the dedupe key — submitting the same URL replaces the existing entry.', 'mxchat'),
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'esc_url_raw',
                    ),
                    'bot_id' => array(
                        'description'       => __('Bot ID (for multi-bot installs). Defaults to "default".', 'mxchat'),
                        'type'              => 'string',
                        'required'          => false,
                        'default'           => 'default',
                        'sanitize_callback' => 'sanitize_key',
                    ),
                    'content_type' => array(
                        'description'       => __('Free-form content type label, e.g. content, manual, faq, page, post. Stored alongside the entry for filtering. Defaults to "manual".', 'mxchat'),
                        'type'              => 'string',
                        'required'          => false,
                        'default'           => 'manual',
                        'sanitize_callback' => 'sanitize_key',
                    ),
                ),
            ),
        ));
    }

    /**
     * Permission callback: validate bearer token in constant time.
     * Returns true on success, WP_Error on failure.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_bearer_token($request) {
        $stored = (string) get_option(self::TOKEN_OPTION, '');
        if ($stored === '') {
            return new WP_Error(
                'mxchat_rest_disabled',
                __('MxChat REST API is disabled. Generate a token from MxChat → API Access in the WordPress admin.', 'mxchat'),
                array('status' => 401)
            );
        }

        $auth = $request->get_header('authorization');
        if (!is_string($auth) || $auth === '') {
            return new WP_Error(
                'mxchat_rest_no_auth',
                __('Missing Authorization header. Expected: Authorization: Bearer <token>', 'mxchat'),
                array('status' => 401)
            );
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
            return new WP_Error(
                'mxchat_rest_bad_auth',
                __('Malformed Authorization header. Expected: Authorization: Bearer <token>', 'mxchat'),
                array('status' => 401)
            );
        }

        $provided = trim($matches[1]);
        if ($provided === '' || !hash_equals($stored, $provided)) {
            return new WP_Error(
                'mxchat_rest_bad_token',
                __('Invalid API token.', 'mxchat'),
                array('status' => 401)
            );
        }

        return true;
    }

    /**
     * GET /health — public, no auth.
     * Reports whether the API is configured and which capabilities are present.
     */
    public function handle_health($request) {
        $token_set = (string) get_option(self::TOKEN_OPTION, '') !== '';

        $options = get_option('mxchat_options', array());
        $embedding_model = isset($options['embedding_model']) ? (string) $options['embedding_model'] : '';

        return rest_ensure_response(array(
            'ok'              => true,
            'plugin_version'  => defined('MXCHAT_VERSION') ? MXCHAT_VERSION : null,
            'token_set'       => $token_set,
            'embedding_model' => $embedding_model,
            'utils_loaded'    => class_exists('MxChat_Utils'),
            'namespace'       => self::REST_NAMESPACE,
        ));
    }

    /**
     * GET /transcripts — return transcript rows with optional filters.
     * Always uses prepared statements; user-supplied strings never concatenated into SQL.
     */
    public function handle_get_transcripts($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'mxchat_chat_transcripts';

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return new WP_Error(
                'mxchat_rest_no_table',
                __('Transcripts table does not exist on this site.', 'mxchat'),
                array('status' => 500)
            );
        }

        $since           = (string) $request->get_param('since');
        $until           = (string) $request->get_param('until');
        $session_id      = (string) $request->get_param('session_id');
        $role            = (string) $request->get_param('role');
        $has_rag_context = (string) $request->get_param('has_rag_context');
        $limit           = (int)    $request->get_param('limit');
        $offset          = (int)    $request->get_param('offset');
        $order           = strtolower((string) $request->get_param('order')) === 'asc' ? 'ASC' : 'DESC';

        $where  = array('1=1');
        $params = array();

        if ($since !== '') {
            $ts = $this->parse_datetime($since);
            if ($ts === null) {
                return new WP_Error('mxchat_rest_bad_since', __('Could not parse `since` parameter as a date.', 'mxchat'), array('status' => 400));
            }
            $where[]  = 'timestamp >= %s';
            $params[] = gmdate('Y-m-d H:i:s', $ts);
        }
        if ($until !== '') {
            $ts = $this->parse_datetime($until);
            if ($ts === null) {
                return new WP_Error('mxchat_rest_bad_until', __('Could not parse `until` parameter as a date.', 'mxchat'), array('status' => 400));
            }
            $where[]  = 'timestamp <= %s';
            $params[] = gmdate('Y-m-d H:i:s', $ts);
        }
        if ($session_id !== '') {
            $where[]  = 'session_id = %s';
            $params[] = $session_id;
        }
        if ($role === 'user' || $role === 'assistant') {
            $where[]  = 'role = %s';
            $params[] = $role;
        }
        if ($has_rag_context === 'yes') {
            $where[] = "(rag_context IS NOT NULL AND rag_context <> '')";
        } elseif ($has_rag_context === 'no') {
            $where[] = "(rag_context IS NULL OR rag_context = '')";
        }

        $where_sql = implode(' AND ', $where);

        // Fixed column list — never user-controlled.
        $columns = 'id, user_id, session_id, role, message, user_email, user_name, user_identifier, originating_page_url, originating_page_title, rag_context, timestamp';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // $where_sql, $columns, $order, $table are all built from server-side
        // whitelisted values; no user input is concatenated. All variable
        // values flow through $wpdb->prepare() via $params + LIMIT/OFFSET.
        $sql = "SELECT $columns FROM $table WHERE $where_sql ORDER BY timestamp $order LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $prepared = $wpdb->prepare($sql, $params);
        $rows     = $wpdb->get_results($prepared, ARRAY_A);
        // phpcs:enable

        // Total count (respecting filters, ignoring limit/offset) for pagination.
        $count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
        if (!empty(array_slice($params, 0, count($params) - 2))) {
            $count_prepared = $wpdb->prepare($count_sql, array_slice($params, 0, count($params) - 2));
        } else {
            $count_prepared = $count_sql;
        }
        $total = (int) $wpdb->get_var($count_prepared);

        $items = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $items[] = array(
                    'id'                     => (int) $row['id'],
                    'user_id'                => (int) $row['user_id'],
                    'session_id'             => (string) $row['session_id'],
                    'role'                   => (string) $row['role'],
                    'message'                => (string) $row['message'],
                    'user_email'             => $row['user_email']             !== null ? (string) $row['user_email']             : null,
                    'user_name'              => $row['user_name']              !== null ? (string) $row['user_name']              : null,
                    'user_identifier'        => $row['user_identifier']        !== null ? (string) $row['user_identifier']        : null,
                    'originating_page_url'   => $row['originating_page_url']   !== null ? (string) $row['originating_page_url']   : null,
                    'originating_page_title' => $row['originating_page_title'] !== null ? (string) $row['originating_page_title'] : null,
                    'has_rag_context'        => !empty($row['rag_context']),
                    'rag_context'            => $row['rag_context']            !== null ? (string) $row['rag_context']            : null,
                    'timestamp'              => (string) $row['timestamp'],
                );
            }
        }

        return rest_ensure_response(array(
            'total'  => $total,
            'count'  => count($items),
            'limit'  => $limit,
            'offset' => $offset,
            'items'  => $items,
        ));
    }

    /**
     * DELETE /transcripts — delete chat data for one or more session_ids.
     *
     * Body (JSON):
     *   {
     *     "session_ids": ["sid1", "sid2", ...],   // required, non-empty
     *     "cascade":     true                      // optional, default true
     *   }
     *
     * Cascading deletes (when cascade=true) also remove rows in:
     *   - wp_mxchat_transcript_translations (any saved translations)
     *   - wp_mxchat_url_clicks              (link-click tracking rows)
     *
     * Hard caps for safety:
     *   - max 1000 session_ids per call
     *   - each session_id must be a non-empty string
     *
     * Response:
     *   {
     *     "session_ids_requested": N,
     *     "transcripts_deleted":   N,
     *     "translations_deleted":  N,
     *     "url_clicks_deleted":    N,
     *     "cascade":               bool
     *   }
     */
    public function handle_delete_transcripts($request) {
        global $wpdb;

        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = array();
        }

        $session_ids_raw = isset($body['session_ids']) ? $body['session_ids'] : null;
        $cascade         = isset($body['cascade']) ? (bool) $body['cascade'] : true;

        if (!is_array($session_ids_raw) || empty($session_ids_raw)) {
            return new WP_Error(
                'mxchat_rest_no_session_ids',
                __('session_ids is required and must be a non-empty array.', 'mxchat'),
                array('status' => 400)
            );
        }

        if (count($session_ids_raw) > 1000) {
            return new WP_Error(
                'mxchat_rest_too_many',
                __('Too many session_ids in a single request. Cap is 1000; split into multiple calls.', 'mxchat'),
                array('status' => 400)
            );
        }

        // Sanitize: must be non-empty strings, dedupe, drop bad values.
        $session_ids = array();
        foreach ($session_ids_raw as $sid) {
            if (is_string($sid) && trim($sid) !== '') {
                $clean = sanitize_text_field($sid);
                if ($clean !== '') {
                    $session_ids[] = $clean;
                }
            }
        }
        $session_ids = array_values(array_unique($session_ids));

        if (empty($session_ids)) {
            return new WP_Error(
                'mxchat_rest_no_valid_session_ids',
                __('session_ids must contain at least one valid non-empty string.', 'mxchat'),
                array('status' => 400)
            );
        }

        $transcripts_table  = $wpdb->prefix . 'mxchat_chat_transcripts';
        $translations_table = $wpdb->prefix . 'mxchat_transcript_translations';
        $url_clicks_table   = $wpdb->prefix . 'mxchat_url_clicks';

        // Verify the main table exists; the others are best-effort.
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $transcripts_table)) !== $transcripts_table) {
            return new WP_Error(
                'mxchat_rest_no_table',
                __('Transcripts table does not exist on this site.', 'mxchat'),
                array('status' => 500)
            );
        }

        $placeholders = implode(',', array_fill(0, count($session_ids), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // $placeholders is a server-built list of literal "%s" tokens — never user-controlled.
        // All values flow through $wpdb->prepare() via $session_ids.
        $deleted_transcripts = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $transcripts_table WHERE session_id IN ($placeholders)",
                $session_ids
            )
        );

        $deleted_translations = 0;
        $deleted_url_clicks   = 0;

        if ($cascade) {
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $translations_table)) === $translations_table) {
                $deleted_translations = (int) $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM $translations_table WHERE session_id IN ($placeholders)",
                        $session_ids
                    )
                );
            }
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $url_clicks_table)) === $url_clicks_table) {
                $deleted_url_clicks = (int) $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM $url_clicks_table WHERE session_id IN ($placeholders)",
                        $session_ids
                    )
                );
            }
        }
        // phpcs:enable

        return rest_ensure_response(array(
            'session_ids_requested' => count($session_ids),
            'transcripts_deleted'   => $deleted_transcripts,
            'translations_deleted'  => $deleted_translations,
            'url_clicks_deleted'    => $deleted_url_clicks,
            'cascade'               => $cascade,
        ));
    }

    /**
     * POST /knowledge — embed + store content in the KB.
     * Wraps MxChat_Utils::submit_content_to_db() so external tools can push
     * content the same way an admin would via the Knowledge UI.
     */
    public function handle_post_knowledge($request) {
        if (!class_exists('MxChat_Utils')) {
            return new WP_Error(
                'mxchat_rest_no_utils',
                __('MxChat_Utils is not loaded.', 'mxchat'),
                array('status' => 500)
            );
        }

        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = array();
        }

        $raw_content = isset($body['content']) ? (string) $body['content'] : '';
        $content     = wp_kses_post(wp_unslash($raw_content));

        $source_url   = isset($body['source_url']) ? esc_url_raw((string) $body['source_url']) : '';
        $bot_id       = isset($body['bot_id'])       ? sanitize_key((string) $body['bot_id'])       : 'default';
        $content_type = isset($body['content_type']) ? sanitize_key((string) $body['content_type']) : 'manual';

        if ($content === '') {
            return new WP_Error('mxchat_rest_no_content', __('content is required.', 'mxchat'), array('status' => 400));
        }
        if ($source_url === '') {
            return new WP_Error('mxchat_rest_no_url', __('source_url is required and must be a valid URL.', 'mxchat'), array('status' => 400));
        }
        if ($bot_id === '') {
            $bot_id = 'default';
        }

        // Pull the embedding API key from MxChat options (same logic as the
        // admin form handler in MxChat_Knowledge_Manager::mxchat_handle_content_submission).
        $bot_options = $this->get_bot_options($bot_id);
        $options     = !empty($bot_options) ? $bot_options : get_option('mxchat_options', array());
        $selected_model = isset($options['embedding_model']) ? (string) $options['embedding_model'] : 'text-embedding-ada-002';

        if (strpos($selected_model, 'voyage') === 0) {
            $api_key = isset($options['voyage_api_key']) ? (string) $options['voyage_api_key'] : '';
        } elseif (strpos($selected_model, 'gemini-embedding') === 0) {
            $api_key = isset($options['gemini_api_key']) ? (string) $options['gemini_api_key'] : '';
        } else {
            $api_key = isset($options['api_key']) ? (string) $options['api_key'] : '';
        }

        if ($api_key === '') {
            return new WP_Error(
                'mxchat_rest_no_api_key',
                __('No embedding API key configured for the selected embedding model. Configure it in MxChat settings before pushing knowledge.', 'mxchat'),
                array('status' => 500)
            );
        }

        // Embedding + chunking can take 10-60s for large documents.
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        @ignore_user_abort(true);

        $result = MxChat_Utils::submit_content_to_db($content, $source_url, $api_key, null, $bot_id, $content_type);

        if (is_wp_error($result)) {
            return new WP_Error(
                'mxchat_rest_kb_failed',
                $result->get_error_message(),
                array('status' => 500)
            );
        }

        return rest_ensure_response(array(
            'success'      => true,
            'source_url'   => $source_url,
            'bot_id'       => $bot_id,
            'content_type' => $content_type,
            'bytes'        => strlen($content),
        ));
    }

    /**
     * Get bot-specific options if the multi-bot add-on is present, else return empty.
     */
    private function get_bot_options($bot_id) {
        if ($bot_id === '' || $bot_id === 'default') {
            return array();
        }
        if (!class_exists('MxChat_Multi_Bot_Manager')) {
            return array();
        }
        $bot_options = apply_filters('mxchat_get_bot_options', array(), $bot_id);
        return is_array($bot_options) ? $bot_options : array();
    }

    /**
     * Try a few formats before falling back to strtotime.
     * Returns a Unix timestamp or null.
     */
    private function parse_datetime($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        return ($ts === false || $ts <= 0) ? null : $ts;
    }
}
