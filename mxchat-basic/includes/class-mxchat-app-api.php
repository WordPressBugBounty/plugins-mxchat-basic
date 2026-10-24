<?php
/**
 * MxChat app API: the endpoints the MxChat mobile app uses (mxchat/v1/app/*).
 *
 * Sign-in: a phone paired by QR code (MxChat_App_Auth, an mxd_ token) acts as the WordPress administrator who
 * paired it, checked live on every request. The shared site API token (MxChat → API Access, meant for
 * integrations) may only READ here and never acts as a WordPress user: every write route answers it
 * 403 mxchat_app_pair_required. The site keeps every AI key and does the work; the app never receives a
 * provider key (settings report keys as "set, ending abcd").
 *
 * Where the WordPress admin already has a handler for the job (saving a setting, listing or toggling
 * actions, content generation, the chat itself) the route runs that handler in-process as the paired
 * administrator, so a change made from the phone goes through exactly the code the admin screen uses.
 * The contract the app builds against is D:\mxchat-app\APP_API.md (Maxwell direct, 2026-10-23).
 *
 * @package MxChat
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Thrown in place of wp_die() while an admin AJAX handler runs inside a REST request. */
class MxChat_App_Api_Halt extends Exception {}

class MxChat_App_Api {

    const REST_NAMESPACE = 'mxchat/v1';
    const API_VERSION    = 1;

    /** Characters of an entry's text the knowledge list returns; the rest is marked truncated. */
    const ENTRY_CONTENT_MAX = 8000;

    /**
     * Requests per WordPress account per window for the routes that spend AI credit: [max, seconds].
     * Filter: mxchat_app_rate_limits. 'assistant' is used by the Admin Chat add-on's Assistant.
     */
    const RATE_LIMITS = array(
        'chat'             => array(30, 600),
        'content_generate' => array(10, 3600),
        'content_edit'     => array(30, 3600),
        'assistant'        => array(40, 600),
    );

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        $routes = array(
            array('/app/info', WP_REST_Server::READABLE, 'handle_info'),
            array('/app/knowledge', WP_REST_Server::READABLE, 'handle_knowledge_list'),
            array('/app/knowledge', WP_REST_Server::CREATABLE, 'handle_knowledge_add'),
            array('/app/knowledge/delete', WP_REST_Server::CREATABLE, 'handle_knowledge_delete'),
            array('/app/settings', WP_REST_Server::READABLE, 'handle_settings_get'),
            array('/app/settings', WP_REST_Server::CREATABLE, 'handle_settings_save'),
            array('/app/actions', WP_REST_Server::READABLE, 'handle_actions_list'),
            array('/app/actions/toggle', WP_REST_Server::CREATABLE, 'handle_actions_toggle'),
            array('/app/content/generate', WP_REST_Server::CREATABLE, 'handle_content_generate'),
            array('/app/content/progress', WP_REST_Server::READABLE, 'handle_content_progress'),
            array('/app/content/history', WP_REST_Server::READABLE, 'handle_content_history'),
            array('/app/content/status', WP_REST_Server::CREATABLE, 'handle_content_status'),
            array('/app/content/delete', WP_REST_Server::CREATABLE, 'handle_content_delete'),
            array('/app/content/post', WP_REST_Server::READABLE, 'handle_content_post'),
            array('/app/content/save', WP_REST_Server::CREATABLE, 'handle_content_save'),
            array('/app/content/edit', WP_REST_Server::CREATABLE, 'handle_content_edit'),
            array('/app/content/edit-status', WP_REST_Server::READABLE, 'handle_content_edit_status'),
            array('/app/chat', WP_REST_Server::CREATABLE, 'handle_chat'),
            array('/app/devices', WP_REST_Server::READABLE, 'handle_devices'),
            array('/app/settings/schema', WP_REST_Server::READABLE, 'handle_settings_schema'),
            array('/app/settings/field', WP_REST_Server::CREATABLE, 'handle_settings_field'),
            array('/app/addons', WP_REST_Server::READABLE, 'handle_addons'),
            array('/app/devices/revoke', WP_REST_Server::CREATABLE, 'handle_device_revoke'),
        );
        // Pairing is the one route without a token: the single-use code is the credential.
        register_rest_route(self::REST_NAMESPACE, '/app/pair', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array($this, 'handle_pair'),
                'permission_callback' => '__return_true',
            ),
        ));
        foreach ($routes as $route) {
            // Every POST changes something or spends AI credit: paired phones only.
            $write = $route[1] !== WP_REST_Server::READABLE;
            register_rest_route(self::REST_NAMESPACE, $route[0], array(
                array(
                    'methods'             => $route[1],
                    'callback'            => array($this, $route[2]),
                    'permission_callback' => array($this, $write ? 'authorize_write' : 'authorize_read'),
                ),
            ));
        }
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function authorize_read($request) {
        return $this->authorize($request, false);
    }

    public function authorize_write($request) {
        return $this->authorize($request, true);
    }

    /**
     * Who is calling (MxChat_App_Auth::identify). A paired phone runs as the WordPress administrator who paired
     * it (identify() has just checked that account still has manage_options). The site API token is shared
     * with integrations and belongs to nobody: it reads with no WordPress user set and cannot write.
     */
    public function authorize($request, $write = false) {
        $who = MxChat_App_Auth::identify($request);
        if (is_wp_error($who)) {
            return $who;
        }
        $this->caller    = $who['kind'];
        $this->device_id = $who['device_id'];
        if ($who['kind'] !== 'device') {
            if ($write) {
                return self::pair_required();
            }
            wp_set_current_user(0);
            return true;
        }
        wp_set_current_user((int) $who['user_id']);
        return true;
    }

    /** 'device' (a paired phone) or 'site' (the shared site API token). */
    private $caller = '';

    /** The paired phone making this request, empty for the site API token. */
    private $device_id = '';

    public static function pair_required() {
        return new WP_Error('mxchat_app_pair_required', __('Making changes needs a phone paired with this site. The site API token can only read.', 'mxchat'), array('status' => 403));
    }

    /**
     * The sign-in for routes other plugins add under mxchat/v1/app/* (the Admin Chat add-on's Assistant,
     * which is per person): a paired phone only, run as the administrator who paired it.
     */
    public static function permission($request) {
        $who = MxChat_App_Auth::identify($request);
        if (is_wp_error($who)) {
            return $who;
        }
        if ($who['kind'] !== 'device') {
            return self::pair_required();
        }
        wp_set_current_user((int) $who['user_id']);
        return true;
    }

    /**
     * Run a read that goes through an admin screen's handler (the actions list, content history). Those
     * handlers need an administrator and a nonce; for the site API token the first administrator stands in for
     * this one read-only call and is unset again afterwards. No write route accepts the site token.
     */
    private function as_reader($callback) {
        if ($this->caller === 'device') {
            return call_user_func($callback);
        }
        $admin = self::first_admin_id();
        if (!$admin) {
            return new WP_Error('mxchat_app_no_admin', __('This site has no administrator account to read this with.', 'mxchat'), array('status' => 500));
        }
        $previous = get_current_user_id();
        wp_set_current_user($admin);
        try {
            return call_user_func($callback);
        } finally {
            wp_set_current_user($previous);
        }
    }

    /**
     * Count one request against a per-account limit; true, or a 429 WP_Error once the window is used up.
     * Counted before the request is checked, so a client retrying a bad request is held too.
     */
    public static function rate_limit($bucket, $user_id = null) {
        $limits = apply_filters('mxchat_app_rate_limits', self::RATE_LIMITS);
        if (!is_array($limits) || !isset($limits[$bucket])) {
            return true;
        }
        $limit  = array_values((array) $limits[$bucket]);
        $max    = (int) ($limit[0] ?? 0);
        $window = max(1, (int) ($limit[1] ?? 60));
        $key    = 'mxchat_app_rl_' . sanitize_key($bucket) . '_' . (int) ($user_id === null ? get_current_user_id() : $user_id);
        $now    = time();
        $state  = get_transient($key);
        if (!is_array($state) || $now - (int) ($state['start'] ?? 0) >= $window) {
            $state = array('start' => $now, 'count' => 0);
        }
        $left = max(1, $window - ($now - (int) $state['start']));
        if ((int) $state['count'] >= $max) {
            /* translators: %s: time until the limit resets, e.g. "5 mins" */
            return new WP_Error('mxchat_app_rate_limited', sprintf(__('Too many requests. Try again in %s.', 'mxchat'), human_time_diff($now, $now + $left)), array('status' => 429, 'retry_after' => $left));
        }
        $state['count'] = (int) $state['count'] + 1;
        set_transient($key, $state, $left);
        return true;
    }

    private static function first_admin_id() {
        $ids = get_users(array('role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID'));
        if (empty($ids)) {
            $ids = get_users(array('capability' => 'manage_options', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID'));
        }
        return empty($ids) ? 0 : (int) $ids[0];
    }

    // ------------------------------------------------------------------
    // Running an admin AJAX handler in-process
    // ------------------------------------------------------------------

    public function halt() {
        throw new MxChat_App_Api_Halt();
    }

    public function die_handler() {
        return array($this, 'halt');
    }

    /**
     * Call a wp_ajax_* handler with $post as the request and return its JSON as an array.
     *
     * The handler's own nonce check passes with a nonce minted here for the current (administrator)
     * user; wp_send_json() ends in wp_die() under DOING_AJAX, which halt() turns into an exception
     * so the REST request carries on. A {success:false} reply becomes a WP_Error with its message.
     */
    private function run_admin_ajax($callable, $nonce_action, $nonce_field, array $post) {
        // The nonce minted below would pass any handler's check, so the caller must be an administrator here.
        if (!current_user_can('manage_options')) {
            return new WP_Error('mxchat_app_forbidden', __('Only an administrator can do this.', 'mxchat'), array('status' => 403));
        }
        if (!defined('DOING_AJAX')) {
            define('DOING_AJAX', true);
        }
        $saved_post    = $_POST;
        $saved_request = $_REQUEST;
        $post[$nonce_field] = wp_create_nonce($nonce_action);
        $_POST    = wp_slash($post);
        $_REQUEST = array_merge($_REQUEST, $_POST);

        add_filter('wp_die_ajax_handler', array($this, 'die_handler'), PHP_INT_MAX);
        add_filter('wp_die_handler', array($this, 'die_handler'), PHP_INT_MAX);
        ob_start();
        $error = null;
        try {
            call_user_func($callable);
        } catch (MxChat_App_Api_Halt $e) {
            // wp_send_json() finished; its JSON is in the buffer.
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $output = (string) ob_get_clean();
        remove_filter('wp_die_ajax_handler', array($this, 'die_handler'), PHP_INT_MAX);
        remove_filter('wp_die_handler', array($this, 'die_handler'), PHP_INT_MAX);
        $_POST    = $saved_post;
        $_REQUEST = $saved_request;

        if ($error !== null) {
            return new WP_Error('mxchat_app_failed', $error, array('status' => 500));
        }
        $data = json_decode(trim($output), true);
        if (!is_array($data) && preg_match('/\{.*\}/s', $output, $m)) {
            $data = json_decode($m[0], true);
        }
        if (!is_array($data)) {
            return new WP_Error('mxchat_app_bad_reply', __('The site returned an unexpected reply.', 'mxchat'), array('status' => 500));
        }
        if (array_key_exists('success', $data) && $data['success'] === false) {
            $detail  = isset($data['data']) ? $data['data'] : '';
            $message = is_array($detail) ? (string) ($detail['message'] ?? '') : (string) $detail;
            return new WP_Error('mxchat_app_refused', $message !== '' ? wp_strip_all_tags($message) : __('The site refused the request.', 'mxchat'), array('status' => 400));
        }
        return $data;
    }

    private function admin_instance() {
        static $admin = null;
        if ($admin === null && class_exists('MxChat_Admin')) {
            $km    = class_exists('MxChat_Knowledge_Manager') ? MxChat_Knowledge_Manager::get_instance() : null;
            $admin = new MxChat_Admin($km);
        }
        return $admin;
    }

    private function content_generator() {
        static $generator = null;
        if ($generator === null && class_exists('MxChat_Content_Generator')) {
            $generator = new MxChat_Content_Generator();
        }
        return $generator;
    }

    // ------------------------------------------------------------------
    // Info
    // ------------------------------------------------------------------

    public function handle_info($request) {
        global $wpdb;
        $options = get_option('mxchat_options', array());
        $options = is_array($options) ? $options : array();

        $backend = $this->uses_pinecone('default') ? 'pinecone' : 'wordpress';
        $entries = null;
        if ($backend === 'wordpress') {
            $table   = $wpdb->prefix . 'mxchat_system_prompt_content';
            $entries = (int) $wpdb->get_var("SELECT COUNT(DISTINCT " . $this->entry_key_sql() . ") FROM $table");
        }

        // Transcript timestamps are UTC; "today" is the site's own day.
        $midnight    = (new DateTimeImmutable('today', wp_timezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $transcripts = $wpdb->prefix . 'mxchat_chat_transcripts';
        $chats_today = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM $transcripts WHERE timestamp >= %s", $midnight));

        return rest_ensure_response(array(
            'api'            => self::API_VERSION,
            'plugin_version' => defined('MXCHAT_VERSION') ? MXCHAT_VERSION : null,
            'site'           => array('name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), 'url' => home_url('/')),
            'pro'            => get_option('mxchat_license_status', 'inactive') === 'active',
            'model'          => (string) ($options['model'] ?? ''),
            'knowledge'      => array('backend' => $backend, 'entries' => $entries),
            'bots'           => $this->bots(),
            'addons'         => class_exists('MxChat_App_Addons') ? MxChat_App_Addons::all() : array(),
            'features'       => class_exists('MxChat_App_Addons') ? MxChat_App_Addons::features() : array(),
            'chats_today'    => $chats_today,
        ));
    }

    private function bots() {
        global $wpdb;
        $bots  = array(array('id' => 'default', 'name' => __('Default', 'mxchat')));
        $table = $wpdb->prefix . 'mxchat_multi_bots';
        if (class_exists('MxChat_Multi_Bot_Database') || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $rows = $wpdb->get_results("SELECT bot_id, bot_name FROM $table ORDER BY bot_name ASC", ARRAY_A);
            foreach ((array) $rows as $row) {
                if ($row['bot_id'] !== 'default') {
                    $bots[] = array('id' => (string) $row['bot_id'], 'name' => (string) $row['bot_name']);
                }
            }
        }
        return $bots;
    }

    // ------------------------------------------------------------------
    // Knowledge base
    // ------------------------------------------------------------------

    /** One entry per source_url; a manual row saved with no address is its own entry, keyed "#<id>". */
    private function entry_key_sql() {
        return "(CASE WHEN source_url IS NULL OR source_url = '' THEN CONCAT('#', id) ELSE source_url END)";
    }

    private function bot_id($raw) {
        $bot_id = sanitize_key((string) $raw);
        return $bot_id === '' ? 'default' : $bot_id;
    }

    private function pinecone_options($bot_id) {
        if (!class_exists('MxChat_Pinecone_Manager')) {
            return array();
        }
        $manager = MxChat_Pinecone_Manager::get_instance();
        return (array) $manager->mxchat_get_bot_pinecone_options($bot_id);
    }

    /** The same test the Knowledge screen uses (ajax_mxchat_paginate_entries). */
    private function uses_pinecone($bot_id) {
        $opts = $this->pinecone_options($bot_id);
        return ($opts['mxchat_use_pinecone'] ?? '0') === '1' && !empty($opts['mxchat_pinecone_api_key']);
    }

    private function entry_type($source_url, $content_type) {
        if ($source_url === '' || stripos($source_url, 'mxchat://') === 0) {
            return 'manual';
        }
        if (stripos($source_url, 'upload://') === 0) {
            return 'upload';
        }
        if ($content_type === 'pdf' || preg_match('/\.pdf(#page=\d+)?$/i', $source_url)) {
            return 'pdf';
        }
        if ($content_type !== '' && $content_type !== 'content' && $content_type !== 'url') {
            return sanitize_key($content_type);
        }
        return 'url';
    }

    private function entry_title($source_url, $stored_title, $content) {
        if ($stored_title !== '') {
            return $stored_title;
        }
        if ($source_url !== '' && class_exists('MxChat_Utils') && method_exists('MxChat_Utils', 'source_title_for')) {
            $registered = (string) MxChat_Utils::source_title_for($source_url);
            if ($registered !== '') {
                return $registered;
            }
        }
        // Q&A entries (the FAQ sync's "Q: ... A: ...", the app's "Question: ...\nAnswer: ...") read best by their question.
        if (preg_match('/^\s*(?:Q|Question):\s*(.+?)(?:\s+A:|\s*\n|$)/s', wp_strip_all_tags($content), $q)) {
            return wp_html_excerpt(trim($q[1]), 90, '…');
        }
        // A text entry the app added on a Pinecone site: its title is in the address (#mxchat-app-note-<title>-<time>).
        if (preg_match('/#mxchat-app-note-(.+)-\d{14}$/', $source_url, $m)) {
            return ucfirst(str_replace('-', ' ', urldecode($m[1])));
        }
        if ($source_url !== '' && preg_match('#^https?://#i', $source_url)) {
            $path = trim((string) wp_parse_url($source_url, PHP_URL_PATH), '/');
            $last = $path === '' ? '' : basename($path);
            return $last !== '' ? urldecode($last) : (string) wp_parse_url($source_url, PHP_URL_HOST);
        }
        $first = trim(strtok(wp_strip_all_tags($content), "\n"));
        return $first === '' ? __('Untitled entry', 'mxchat') : wp_html_excerpt($first, 70, '…');
    }

    private function join_content(array $chunks) {
        $content   = '';
        $truncated = false;
        foreach ($chunks as $chunk) {
            if (strlen($content) >= self::ENTRY_CONTENT_MAX) {
                $truncated = true;
                break;
            }
            $content .= ($content === '' ? '' : "\n\n") . (string) $chunk;
        }
        if (strlen($content) > self::ENTRY_CONTENT_MAX) {
            $content   = wp_html_excerpt($content, self::ENTRY_CONTENT_MAX, '');
            $truncated = true;
        }
        return array($content, $truncated);
    }

    public function handle_knowledge_list($request) {
        global $wpdb;
        $bot_id   = $this->bot_id($request->get_param('bot_id'));
        $search   = trim(sanitize_text_field((string) $request->get_param('search')));
        $page     = max(1, (int) $request->get_param('page'));
        $per_page = (int) $request->get_param('per_page');
        $per_page = $per_page < 1 ? 25 : min(100, $per_page);

        if ($this->uses_pinecone($bot_id)) {
            return $this->knowledge_list_pinecone($bot_id, $search, $page, $per_page);
        }

        $table = $wpdb->prefix . 'mxchat_system_prompt_content';
        $key   = $this->entry_key_sql();
        $where  = '1=1';
        $params = array();
        if ($search !== '') {
            $like   = '%' . $wpdb->esc_like($search) . '%';
            $where  = '(article_content LIKE %s OR source_url LIKE %s OR title LIKE %s)';
            $params = array($like, $like, $like);
        }

        $count_sql = "SELECT COUNT(DISTINCT $key) FROM $table WHERE $where";
        $total     = (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, $params) : $count_sql);

        $keys = $wpdb->get_results($wpdb->prepare(
            "SELECT $key AS entry_key, MAX(timestamp) AS updated, COUNT(*) AS chunks FROM $table WHERE $where GROUP BY entry_key ORDER BY updated DESC LIMIT %d OFFSET %d",
            array_merge($params, array($per_page, ($page - 1) * $per_page))
        ), ARRAY_A);

        $urls = array();
        $ids  = array();
        foreach ((array) $keys as $row) {
            if (strpos($row['entry_key'], '#') === 0) {
                $ids[] = (int) substr($row['entry_key'], 1);
            } else {
                $urls[] = $row['entry_key'];
            }
        }
        $rows = array();
        if ($urls) {
            $in   = implode(',', array_fill(0, count($urls), '%s'));
            $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare("SELECT id, source_url, title, content_type, article_content FROM $table WHERE source_url IN ($in) ORDER BY id ASC", $urls), ARRAY_A));
        }
        if ($ids) {
            $in   = implode(',', array_fill(0, count($ids), '%d'));
            $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare("SELECT id, source_url, title, content_type, article_content FROM $table WHERE id IN ($in) ORDER BY id ASC", $ids), ARRAY_A));
        }
        $by_key = array();
        foreach ($rows as $row) {
            $row_key            = ($row['source_url'] === null || $row['source_url'] === '') ? '#' . $row['id'] : $row['source_url'];
            $by_key[$row_key][] = $row;
        }

        $entries = array();
        foreach ((array) $keys as $row) {
            $group  = isset($by_key[$row['entry_key']]) ? $by_key[$row['entry_key']] : array();
            $source = strpos($row['entry_key'], '#') === 0 ? '' : $row['entry_key'];
            list($content, $truncated) = $this->join_content(wp_list_pluck($group, 'article_content'));
            $stored_title = '';
            foreach ($group as $chunk) {
                if (!empty($chunk['title'])) {
                    $stored_title = (string) $chunk['title'];
                    break;
                }
            }
            $entries[] = array(
                'key'        => $row['entry_key'],
                'source_url' => $source,
                'title'      => $this->entry_title($source, $stored_title, $content),
                'type'       => $this->entry_type($source, isset($group[0]['content_type']) ? (string) $group[0]['content_type'] : ''),
                'chunks'     => (int) $row['chunks'],
                'updated_at' => (string) $row['updated'],
                'content'    => $content,
                'truncated'  => $truncated,
            );
        }

        return rest_ensure_response(array(
            'backend'     => 'wordpress',
            'total'       => $total,
            'page'        => $page,
            'total_pages' => (int) ceil($total / $per_page),
            'entries'     => $entries,
        ));
    }

    /** Pinecone pages by record (chunk), as the Knowledge screen does; chunks are grouped per address here. */
    private function knowledge_list_pinecone($bot_id, $search, $page, $per_page) {
        $manager = MxChat_Pinecone_Manager::get_instance();
        $records = $manager->mxchat_fetch_pinecone_records($this->pinecone_options($bot_id), $search, $page, $per_page, $bot_id, '');
        $data    = isset($records['data']) ? (array) $records['data'] : array();
        $total   = (int) ($records['total'] ?? 0);

        $groups = array();
        foreach ($data as $record) {
            $record = (object) $record;
            $source = (string) ($record->source_url ?? '');
            $key    = $source !== '' ? $source : '#' . (string) ($record->id ?? '');
            if (!isset($groups[$key])) {
                // mxchat_fetch_pinecone_records() names these type and created_at (unix), not content_type / timestamp.
                $groups[$key] = array('source' => $source, 'chunks' => array(), 'updated' => '', 'type' => (string) ($record->type ?? $record->content_type ?? ''));
            }
            $groups[$key]['chunks'][(int) ($record->chunk_index ?? count($groups[$key]['chunks']))] = (string) ($record->article_content ?? '');
            $created = $record->created_at ?? ($record->timestamp ?? '');
            $stamp   = is_numeric($created) ? gmdate('Y-m-d H:i:s', (int) $created) : (string) $created;
            if ($stamp > $groups[$key]['updated']) {
                $groups[$key]['updated'] = $stamp;
            }
        }

        $entries = array();
        foreach ($groups as $key => $group) {
            ksort($group['chunks']);
            list($content, $truncated) = $this->join_content($group['chunks']);
            $entries[] = array(
                'key'        => $key,
                'source_url' => $group['source'],
                'title'      => $this->entry_title($group['source'], '', $content),
                'type'       => $this->entry_type($group['source'], $group['type']),
                'chunks'     => count($group['chunks']),
                'updated_at' => $group['updated'],
                'content'    => $content,
                'truncated'  => $truncated,
            );
        }

        return rest_ensure_response(array(
            'backend'     => 'pinecone',
            'total'       => $total,
            'page'        => $page,
            'total_pages' => (int) ceil($total / $per_page),
            'entries'     => $entries,
        ));
    }

    /** The embedding key the importers use for this bot (MxChat_Rest_Api::handle_post_knowledge's logic). */
    private function embedding_key($bot_id) {
        $bot_options = apply_filters('mxchat_get_bot_options', array(), $bot_id);
        $options     = (is_array($bot_options) && !empty($bot_options)) ? $bot_options : get_option('mxchat_options', array());
        $preflight   = MxChat_Utils::embedding_preflight(is_array($options) ? $options : array());
        if (!$preflight['ok']) {
            return new WP_Error('mxchat_rest_no_api_key', $preflight['reason'], array('status' => 500));
        }
        return $preflight['api_key'];
    }

    public function handle_knowledge_add($request) {
        if (!class_exists('MxChat_Utils')) {
            return new WP_Error('mxchat_rest_no_utils', __('MxChat_Utils is not loaded.', 'mxchat'), array('status' => 500));
        }
        $body   = (array) $request->get_json_params();
        $kind   = sanitize_key((string) ($body['kind'] ?? 'text'));
        $bot_id = $this->bot_id($body['bot_id'] ?? 'default');

        $api_key = $this->embedding_key($bot_id);
        if (is_wp_error($api_key)) {
            return $api_key;
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        @ignore_user_abort(true);

        if ($kind === 'text') {
            $content = wp_kses_post(wp_unslash((string) ($body['content'] ?? '')));
            $title   = sanitize_text_field((string) ($body['title'] ?? ''));
            if (trim(wp_strip_all_tags($content)) === '') {
                return new WP_Error('mxchat_rest_no_content', __('Write something to add.', 'mxchat'), array('status' => 400));
            }
            if ($this->uses_pinecone($bot_id)) {
                // Pinecone gives a URL-less entry a random manual_* id that nothing can find again; an
                // address of its own (the site with a unique fragment) keys it as md5(address), so the
                // app can delete it, and delete_chunks_for_url() clears every chunk.
                $source = home_url('/') . '#mxchat-app-note-' . sanitize_title($title !== '' ? $title : 'note') . '-' . gmdate('YmdHis');
                $result = MxChat_Utils::submit_content_to_db($content, $source, $api_key, null, $bot_id, 'manual', $title);
                if (is_wp_error($result)) {
                    return new WP_Error('mxchat_rest_kb_failed', $result->get_error_message(), array('status' => 500));
                }
                return rest_ensure_response(array('status' => 'added', 'key' => esc_url_raw($source), 'message' => __('Added to the knowledge base.', 'mxchat')));
            }

            // Saved like the Knowledge screen's manual entry: the store gives it its own
            // mxchat://manual-content/... identity (any address passed in is replaced), so read that back.
            global $wpdb;
            $table   = $wpdb->prefix . 'mxchat_system_prompt_content';
            $last_id = (int) $wpdb->get_var("SELECT MAX(id) FROM $table");
            $result  = MxChat_Utils::submit_content_to_db($content, '', $api_key, null, $bot_id, 'manual');
            if (is_wp_error($result)) {
                return new WP_Error('mxchat_rest_kb_failed', $result->get_error_message(), array('status' => 500));
            }
            $key = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT source_url FROM $table WHERE id > %d AND content_type = 'manual' ORDER BY id ASC LIMIT 1",
                $last_id
            ));
            if ($key !== '' && $title !== '') {
                $wpdb->update($table, array('title' => $title), array('source_url' => $key), array('%s'), array('%s'));
                MxChat_Utils::set_source_title($key, $title);
            }
            return rest_ensure_response(array('status' => 'added', 'key' => $key, 'message' => __('Added to the knowledge base.', 'mxchat')));
        }

        if ($kind !== 'url') {
            return new WP_Error('mxchat_app_bad_kind', __('kind must be text or url.', 'mxchat'), array('status' => 400));
        }
        return $this->knowledge_add_url((string) ($body['url'] ?? ''), $bot_id, $api_key);
    }

    /** A page, PDF link or sitemap, the way MxChat_Knowledge_Manager::mxchat_handle_sitemap_submission() imports it. */
    private function knowledge_add_url($raw_url, $bot_id, $api_key) {
        $url = esc_url_raw(trim($raw_url));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return new WP_Error('mxchat_rest_no_url', __('Enter a full web address, starting with https://', 'mxchat'), array('status' => 400));
        }
        if (strpos($url, 'drive.google.com') !== false) {
            $file_id = '';
            if (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $url, $m) || preg_match('#/file/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
                $file_id = $m[1];
            }
            if ($file_id !== '') {
                $url = 'https://drive.google.com/uc?export=download&id=' . $file_id;
            }
        }
        if (!class_exists('MxChat_Knowledge_Manager')) {
            return new WP_Error('mxchat_app_no_km', __('The knowledge importer is not loaded.', 'mxchat'), array('status' => 500));
        }
        $km = MxChat_Knowledge_Manager::get_instance();

        $response = wp_remote_get($url, array(
            'timeout'    => 30,
            'sslverify'  => false,
            'user-agent' => function_exists('mxchat_ingest_user_agent') ? mxchat_ingest_user_agent() : 'MxChat',
            'headers'    => array('Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'),
        ));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $why = is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response);
            /* translators: %s: the error */
            return new WP_Error('mxchat_app_fetch_failed', sprintf(__('Could not fetch that address: %s', 'mxchat'), $why), array('status' => 400));
        }
        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        $body         = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            return new WP_Error('mxchat_app_fetch_empty', __('That address returned an empty page.', 'mxchat'), array('status' => 400));
        }

        // PDFs and sitemaps go to the import queue, which the Knowledge screen works through.
        $queued_note = __('Queued. Open MxChat → Knowledge in WordPress to finish the import; it runs while that screen is open.', 'mxchat');
        if ($km->mxchat_is_pdf_url($url, $response)) {
            $result = $km->mxchat_handle_pdf_for_knowledge_base($url, $response, $bot_id);
            if ($result !== 'queued') {
                return new WP_Error('mxchat_app_queue_failed', is_string($result) && $result !== '' ? $result : __('The PDF could not be queued.', 'mxchat'), array('status' => 500));
            }
            return rest_ensure_response(array('status' => 'queued', 'key' => $url, 'message' => $queued_note));
        }
        if (strpos($content_type, 'xml') !== false || strpos($body, '<urlset') !== false) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);
            libxml_clear_errors();
            if ($xml === false) {
                return new WP_Error('mxchat_app_bad_sitemap', __('That sitemap is not valid XML.', 'mxchat'), array('status' => 400));
            }
            $result = $km->mxchat_handle_sitemap_for_knowledge_base($xml, $url, $bot_id);
            if ($result !== 'queued') {
                return new WP_Error('mxchat_app_queue_failed', is_string($result) && $result !== '' ? $result : __('The sitemap could not be queued.', 'mxchat'), array('status' => 500));
            }
            return rest_ensure_response(array('status' => 'queued', 'key' => $url, 'message' => $queued_note));
        }

        $text = $km->mxchat_sanitize_content_for_api($km->mxchat_extract_main_content($body));
        if (trim((string) $text) === '') {
            return new WP_Error('mxchat_app_no_text', __('No readable text was found on that page.', 'mxchat'), array('status' => 400));
        }
        $result = MxChat_Utils::submit_content_to_db($text, $url, $api_key, null, $bot_id, 'url', MxChat_Utils::extract_page_title($body));
        if (is_wp_error($result)) {
            return new WP_Error('mxchat_rest_kb_failed', $result->get_error_message(), array('status' => 500));
        }
        return rest_ensure_response(array('status' => 'added', 'key' => $url, 'message' => __('Added to the knowledge base.', 'mxchat')));
    }

    public function handle_knowledge_delete($request) {
        global $wpdb;
        $body   = (array) $request->get_json_params();
        $key    = trim((string) ($body['key'] ?? ''));
        $bot_id = $this->bot_id($body['bot_id'] ?? 'default');
        if ($key === '') {
            return new WP_Error('mxchat_app_no_key', __('key is required.', 'mxchat'), array('status' => 400));
        }

        if (strpos($key, '#') === 0) {
            if ($this->uses_pinecone($bot_id)) {
                return new WP_Error('mxchat_app_cannot_delete', __('Delete this entry from MxChat → Knowledge in WordPress.', 'mxchat'), array('status' => 400));
            }
            $removed = (int) $wpdb->delete($wpdb->prefix . 'mxchat_system_prompt_content', array('id' => (int) substr($key, 1)), array('%d'));
            return rest_ensure_response(array('deleted' => $removed > 0, 'entries_removed' => $removed));
        }

        // The same removal DELETE /knowledge does: every chunk, every backend, plus the Vector Store mirror.
        $source  = preg_match('#^(mxchat|upload)://#i', $key) ? sanitize_text_field($key) : esc_url_raw($key);
        $before  = (int) MxChat_Utils::count_chunks_for_url($source, $bot_id);
        $outcome = MxChat_Utils::delete_chunks_for_url($source, $bot_id);
        if (is_wp_error($outcome)) {
            return new WP_Error('mxchat_app_delete_failed', $outcome->get_error_message(), array('status' => 500));
        }
        return rest_ensure_response(array('deleted' => $before > 0, 'entries_removed' => $before));
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    /** The settings the app shows, in order. Every key is one the Settings screen autosaves. */
    private function setting_groups() {
        return array(
            array('id' => 'chatbot', 'label' => __('Chatbot', 'mxchat'), 'fields' => array(
                array('key' => 'top_bar_title', 'label' => __('Chat title', 'mxchat'), 'type' => 'text', 'help' => __('Shown at the top of the chat window.', 'mxchat')),
                array('key' => 'intro_message', 'label' => __('Welcome message', 'mxchat'), 'type' => 'textarea'),
                array('key' => 'system_prompt_instructions', 'label' => __('Instructions', 'mxchat'), 'type' => 'textarea', 'help' => __('How the chatbot behaves and answers.', 'mxchat')),
                array('key' => 'model', 'label' => __('AI model', 'mxchat'), 'type' => 'select'),
                array('key' => 'input_copy', 'label' => __('Message box placeholder', 'mxchat'), 'type' => 'text'),
                array('key' => 'pre_chat_message', 'label' => __('Pre-chat bubble', 'mxchat'), 'type' => 'text', 'help' => __('Shown beside the chat button before it is opened.', 'mxchat')),
                array('key' => 'popular_question_1', 'label' => __('Quick question 1', 'mxchat'), 'type' => 'text'),
                array('key' => 'popular_question_2', 'label' => __('Quick question 2', 'mxchat'), 'type' => 'text'),
                array('key' => 'popular_question_3', 'label' => __('Quick question 3', 'mxchat'), 'type' => 'text'),
            )),
            array('id' => 'behavior', 'label' => __('Behavior', 'mxchat'), 'fields' => array(
                array('key' => 'enable_streaming_toggle', 'label' => __('Stream replies as they are written', 'mxchat'), 'type' => 'toggle'),
                array('key' => 'chat_persistence_toggle', 'label' => __('Keep the conversation across pages', 'mxchat'), 'type' => 'toggle'),
                array('key' => 'contextual_awareness_toggle', 'label' => __('Read the page the visitor is on', 'mxchat'), 'type' => 'toggle'),
                array('key' => 'citation_links_toggle', 'label' => __('Link to sources in answers', 'mxchat'), 'type' => 'toggle'),
                array('key' => 'satisfaction_rating_enabled', 'label' => __('Ask visitors to rate the chat', 'mxchat'), 'type' => 'toggle'),
                array('key' => 'similarity_threshold', 'label' => __('Knowledge match threshold', 'mxchat'), 'type' => 'number', 'min' => 20, 'max' => 85, 'suffix' => '%', 'help' => __('Lower finds more knowledge, higher only close matches.', 'mxchat')),
            )),
            array('id' => 'leads', 'label' => __('Lead capture', 'mxchat'), 'fields' => array(
                array('key' => 'enable_email_block', 'label' => __('Ask for an email before chatting', 'mxchat'), 'type' => 'toggle'),
                array('key' => 'enable_name_field', 'label' => __('Ask for a name too', 'mxchat'), 'type' => 'toggle'),
            )),
            array('id' => 'live_agent', 'label' => __('Live agent', 'mxchat'), 'fields' => array(
                array('key' => 'live_agent_status', 'label' => __('Slack live agent', 'mxchat'), 'type' => 'toggle'),
                array('key' => 'telegram_status', 'label' => __('Telegram live agent', 'mxchat'), 'type' => 'toggle'),
                array('key' => 'live_agent_away_message', 'label' => __('Away message', 'mxchat'), 'type' => 'textarea'),
            )),
        );
    }

    /** Provider keys the app can see as set / not set and replace. Values never leave the site. */
    private function key_fields() {
        return array(
            'api_key'            => 'OpenAI',
            'claude_api_key'     => 'Anthropic (Claude)',
            'gemini_api_key'     => 'Google Gemini',
            'xai_api_key'        => 'xAI (Grok)',
            'deepseek_api_key'   => 'DeepSeek',
            'openrouter_api_key' => 'OpenRouter',
            'voyage_api_key'     => 'Voyage AI',
            'brave_api_key'      => 'Brave Search',
        );
    }

    private function setting_field($key) {
        foreach ($this->setting_groups() as $group) {
            foreach ($group['fields'] as $field) {
                if ($field['key'] === $key) {
                    return $field;
                }
            }
        }
        return null;
    }

    private function model_options($current, array $options) {
        return MxChat_App_Settings::model_options($current, $options);
    }

    public function handle_settings_get($request) {
        $options = get_option('mxchat_options', array());
        $options = is_array($options) ? $options : array();

        $groups = array();
        foreach ($this->setting_groups() as $group) {
            $fields = array();
            foreach ($group['fields'] as $field) {
                $value = $options[$field['key']] ?? '';
                if ($field['type'] === 'toggle') {
                    $value = ($value === 'on' || $value === '1' || $value === 1 || $value === true) ? 'on' : 'off';
                } elseif ($field['type'] === 'number') {
                    $value = (int) ($value === '' ? ($field['min'] ?? 0) : $value);
                    if ($field['key'] === 'similarity_threshold' && $value > 0 && $value < 1) {
                        $value = (int) round($value * 100); // a fraction from an older save
                    }
                } else {
                    $value = is_scalar($value) ? (string) $value : '';
                }
                $field['value'] = $value;
                if ($field['type'] === 'select' && $field['key'] === 'model') {
                    $field['options'] = $this->model_options((string) $value, $options);
                }
                $fields[] = $field;
            }
            $groups[] = array('id' => $group['id'], 'label' => $group['label'], 'fields' => $fields);
        }

        $keys = array();
        foreach ($this->key_fields() as $key => $label) {
            $stored = trim((string) ($options[$key] ?? ''));
            $keys[] = array('key' => $key, 'label' => $label, 'set' => $stored !== '', 'ending' => $stored === '' ? '' : substr($stored, -4));
        }

        return rest_ensure_response(array('groups' => $groups, 'keys' => $keys));
    }

    public function handle_settings_save($request) {
        $body  = (array) $request->get_json_params();
        $key   = (string) ($body['key'] ?? '');
        $value = $body['value'] ?? '';
        $value = is_scalar($value) ? (string) $value : '';

        $field  = $this->setting_field($key);
        $is_key = array_key_exists($key, $this->key_fields());
        if (!$field && !$is_key) {
            return new WP_Error('mxchat_app_bad_setting', __('That setting cannot be changed from the app.', 'mxchat'), array('status' => 400));
        }

        // The admin autosave stores most fields as given and leaves cleaning to the options.php sanitizer,
        // which is not attached outside wp-admin; clean by type first so the stored value is the same.
        if ($is_key) {
            $value = sanitize_text_field($value);
        } elseif ($field['type'] === 'toggle') {
            $value = ($value === 'on' || $value === '1' || $value === 'true') ? 'on' : 'off';
        } elseif ($field['type'] === 'number') {
            $value = (string) max((int) ($field['min'] ?? 0), min((int) ($field['max'] ?? PHP_INT_MAX), (int) $value));
        } elseif ($field['type'] === 'textarea') {
            $value = $key === 'intro_message' ? $value : sanitize_textarea_field($value);
        } else {
            $value = sanitize_text_field($value);
        }

        if (!class_exists('MxChat_Ajax_Handler')) {
            return new WP_Error('mxchat_app_no_handler', __('The settings handler is not loaded.', 'mxchat'), array('status' => 500));
        }
        $handler = new MxChat_Ajax_Handler();
        $result  = $this->run_admin_ajax(array($handler, 'mxchat_save_setting_callback'), 'mxchat_save_setting_nonce', '_ajax_nonce', array(
            'name'  => 'mxchat_options[' . $key . ']',
            'value' => $value,
        ));
        if (is_wp_error($result)) {
            return $result;
        }

        $saved = get_option('mxchat_options', array());
        $now   = is_array($saved) && array_key_exists($key, $saved) ? $saved[$key] : $value;
        if ($is_key) {
            $now = trim((string) $now);
            return rest_ensure_response(array('saved' => true, 'key' => $key, 'value' => array('set' => $now !== '', 'ending' => $now === '' ? '' : substr($now, -4))));
        }
        return rest_ensure_response(array('saved' => true, 'key' => $key, 'value' => $field['type'] === 'number' ? (int) $now : (string) $now));
    }

    /** The full settings schema (MxChat_App_Settings): sections → groups → fields, values filled in. */
    public function handle_settings_schema($request) {
        return rest_ensure_response(array('version' => 1, 'sections' => MxChat_App_Settings::schema()));
    }

    /**
     * Save one field of the schema by its id. Fields with their own `save` callable use it; the rest go
     * through the WordPress settings screen's autosave handler under their autosave name.
     */
    public function handle_settings_field($request) {
        $body  = (array) $request->get_json_params();
        $id    = (string) ($body['id'] ?? '');
        $field = MxChat_App_Settings::field($id);
        if (!$field || in_array($field['type'] ?? '', array('info'), true)) {
            return new WP_Error('mxchat_app_bad_setting', __('That setting cannot be changed from the app.', 'mxchat'), array('status' => 400));
        }
        $value = MxChat_App_Settings::clean($field, $body['value'] ?? '');
        if (is_wp_error($value)) {
            return $value;
        }
        if (isset($field['save']) && is_callable($field['save'])) {
            $result = call_user_func($field['save'], $value);
            if (is_wp_error($result)) {
                return $result;
            }
        } else {
            if (!class_exists('MxChat_Ajax_Handler') || empty($field['name'])) {
                return new WP_Error('mxchat_app_no_handler', __('The settings handler is not loaded.', 'mxchat'), array('status' => 500));
            }
            $result = $this->run_admin_ajax(array(new MxChat_Ajax_Handler(), 'mxchat_save_setting_callback'), 'mxchat_save_setting_nonce', '_ajax_nonce', array(
                'name'  => $field['name'],
                'value' => $value,
            ));
            if (is_wp_error($result)) {
                return $result;
            }
        }
        return rest_ensure_response(array('saved' => true, 'id' => $id, 'value' => MxChat_App_Settings::value($field)));
    }

    /** Installed MxChat add-ons: active, licensed, version, and the settings section the app shows for each. */
    public function handle_addons($request) {
        return rest_ensure_response(array('pro' => get_option('mxchat_license_status', 'inactive') === 'active', 'addons' => MxChat_App_Addons::all()));
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    public function handle_actions_list($request) {
        $admin = $this->admin_instance();
        if (!$admin) {
            return new WP_Error('mxchat_app_no_handler', __('The actions handler is not loaded.', 'mxchat'), array('status' => 500));
        }
        $data = $this->as_reader(function () use ($admin, $request) {
            return $this->run_admin_ajax(array($admin, 'mxchat_fetch_actions_list'), 'mxchat_actions_nonce', 'security', array(
                'page'     => max(1, (int) $request->get_param('page')),
                'per_page' => 50,
                'search'   => sanitize_text_field((string) $request->get_param('search')),
            ));
        });
        if (is_wp_error($data)) {
            return $data;
        }
        $payload = (array) ($data['data'] ?? array());
        $actions = array();
        foreach ((array) ($payload['actions'] ?? array()) as $action) {
            $actions[] = array(
                'id'        => (int) $action['id'],
                'label'     => (string) $action['label'],
                'type'      => (string) ($action['callback_label'] ?? $action['callback_function']),
                'callback'  => (string) $action['callback_function'],
                'enabled'   => (bool) $action['enabled'],
                'threshold' => (int) $action['threshold'],
                'phrases'   => (int) ($action['individual_phrase_count'] ?? 0),
            );
        }
        return rest_ensure_response(array(
            'total'       => (int) ($payload['total_actions'] ?? 0),
            'page'        => (int) ($payload['page'] ?? 1),
            'total_pages' => (int) ($payload['total_pages'] ?? 0),
            'actions'     => $actions,
        ));
    }

    public function handle_actions_toggle($request) {
        $body  = (array) $request->get_json_params();
        $id    = (int) ($body['id'] ?? 0);
        $on    = !empty($body['enabled']) && $body['enabled'] !== 'false';
        $admin = $this->admin_instance();
        if (!$admin) {
            return new WP_Error('mxchat_app_no_handler', __('The actions handler is not loaded.', 'mxchat'), array('status' => 500));
        }
        $data = $this->run_admin_ajax(array($admin, 'mxchat_toggle_action_status'), 'mxchat_actions_nonce', 'security', array(
            'action_id' => $id,
            'enabled'   => $on ? 1 : 0,
        ));
        if (is_wp_error($data)) {
            return $data;
        }
        return rest_ensure_response(array('id' => $id, 'enabled' => (bool) ($data['data']['enabled'] ?? $on)));
    }

    // ------------------------------------------------------------------
    // Content generation
    // ------------------------------------------------------------------

    /**
     * The start of MxChat_Content_Generator::handle_generate_content(): the same progress key and
     * parameters, then the generator's own private worker (run_background_generation) writes the post in
     * this PHP process after the reply has gone out, exactly as the admin screen's request does. The
     * admin handler can't be reused whole: it finishes the HTTP response itself, in its AJAX shape.
     */
    public function handle_content_generate($request) {
        $limited = self::rate_limit('content_generate');
        if (is_wp_error($limited)) {
            return $limited;
        }
        $generator = $this->content_generator();
        if (!$generator || !method_exists($generator, 'handle_generate_content')) {
            return new WP_Error('mxchat_app_no_handler', __('The content generator is not loaded.', 'mxchat'), array('status' => 500));
        }
        $body   = (array) $request->get_json_params();
        $prompt = sanitize_textarea_field((string) ($body['prompt'] ?? ''));
        if (trim($prompt) === '') {
            return new WP_Error('mxchat_app_no_prompt', __('Describe what to write.', 'mxchat'), array('status' => 400));
        }

        $progress_key = 'mxchat_content_progress_' . get_current_user_id() . '_' . time();
        $params       = array(
            'secret'               => wp_generate_password(32, false),
            'user_id'              => get_current_user_id(),
            'prompt'               => $prompt,
            'content_type'         => ($body['content_type'] ?? 'post') === 'page' ? 'page' : 'post',
            'post_status'          => ($body['post_status'] ?? 'draft') === 'publish' ? 'publish' : 'draft',
            'schedule_date'        => '',
            'layout'               => 'fullwidth',
            'title_display'        => 'hide',
            'template_mode'        => 'off',
            'custom_system_prompt' => '',
        );
        $worker = Closure::bind(function ($key, $args) {
            $this->update_progress($key, 'starting', __('Starting generation...', 'mxchat'), 5);
            set_transient($key . '_params', $args, 600);
            return function () use ($key, $args) {
                $this->run_background_generation($key, $args);
            };
        }, $generator, get_class($generator));
        $run = $worker($progress_key, $params);

        $this->reply_and_continue(array('progress_key' => $progress_key));
        $run();
        exit;
    }

    /**
     * Send $payload as the whole HTTP reply now and keep this PHP process running for the slow part:
     * the generator's strategy for every host (LiteSpeed, PHP-FPM, others). A proxy in front of the site
     * (Cloudflare cuts a request at 100 s) never sees a long request this way.
     */
    private function reply_and_continue(array $payload) {
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $json = wp_json_encode($payload);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        status_header(200);
        header('Content-Type: application/json; charset=utf-8');
        if (function_exists('litespeed_finish_request')) {
            echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            litespeed_finish_request();
        } elseif (function_exists('fastcgi_finish_request')) {
            echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            fastcgi_finish_request();
        } else {
            header('Connection: close');
            header('Content-Encoding: none');
            header('Content-Length: ' . strlen($json));
            echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            flush();
        }
    }

    /** Call one of the generator's private helpers, so the app and the Content screen share one implementation. */
    private function generator_call($method, array $args = array()) {
        $generator = $this->content_generator();
        $call      = Closure::bind(function ($name, $values) {
            return $this->$name(...$values);
        }, $generator, get_class($generator));
        return $call($method, $args);
    }

    /** A post the content generator wrote, or the error the app shows. */
    private function generated_post($post_id) {
        $post = get_post((int) $post_id);
        if (!$post || get_post_meta($post->ID, '_mxchat_generated', true) !== '1') {
            return new WP_Error('mxchat_app_not_generated', __('This post was not created by the content generator.', 'mxchat'), array('status' => 404));
        }
        return $post;
    }

    /**
     * One generated post to read and edit in the app.
     *
     * `rendered` is what a visitor sees: the content through the_content (blocks, shortcodes) with the
     * generator's CSS. `edit` splits it the way handle_content_edit() does: the embedded CSS block
     * (/* mxchat-css *\/) apart from the HTML, so the app edits only the HTML and save rebuilds the CSS.
     */
    public function handle_content_post($request) {
        if (!$this->content_generator()) {
            return new WP_Error('mxchat_app_no_handler', __('The content generator is not loaded.', 'mxchat'), array('status' => 500));
        }
        $post = $this->generated_post($request->get_param('id'));
        if (is_wp_error($post)) {
            return $post;
        }
        $content    = (string) $post->post_content;
        $embedded   = strpos($content, '/* mxchat-css */') !== false;
        $ai_css     = (string) get_post_meta($post->ID, '_mxchat_content_css', true);
        $fullwidth  = get_post_meta($post->ID, '_mxchat_fullwidth', true) === '1';
        $hide_title = get_post_meta($post->ID, '_mxchat_hide_title', true) === '1';

        // Posts from before the embedded format get their CSS from the theme head (inject_generated_css).
        $head_css = $embedded ? '' : (string) $this->generator_call('get_generated_css', array($fullwidth, $ai_css));

        $GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the_content needs the post in scope
        setup_postdata($post);
        $rendered = $head_css . apply_filters('the_content', $content);
        wp_reset_postdata();

        $css = $head_css;
        if ($embedded && preg_match_all('/<style>\/\* mxchat-css \*\/.*?<\/style>/is', $content, $blocks)) {
            $css = implode("\n", $blocks[0]);
        }
        $html = trim((string) preg_replace('/<style>\/\* mxchat-css \*\/.*?<\/style>\s*/is', '', $content));

        $date_gmt = $post->post_date_gmt !== '0000-00-00 00:00:00' ? (string) $post->post_date_gmt : (string) get_gmt_from_date($post->post_date);
        return rest_ensure_response(array(
            'post_id'   => (int) $post->ID,
            'title'     => (string) $post->post_title,
            'status'    => (string) $post->post_status,
            'post_type' => (string) $post->post_type,
            'date'      => $date_gmt,
            'permalink' => (string) get_permalink($post),
            'edit_url'  => (string) get_edit_post_link($post->ID, 'raw'),
            'rendered'  => $rendered,
            'edit'      => array('css' => $css, 'html' => $html),
            'meta'      => array(
                'description' => (string) $this->generator_call('get_meta_description', array($post->ID)),
                'keyword'     => (string) $this->generator_call('get_focus_keyword', array($post->ID)),
                'excerpt'     => (string) $post->post_excerpt,
            ),
        ));
    }

    /**
     * Save a title and/or the HTML edited in the app. The HTML goes through the generator's own cleaner and
     * gets its CSS layers rebuilt exactly as handle_content_edit() rebuilds them after an AI edit.
     */
    public function handle_content_save($request) {
        if (!$this->content_generator()) {
            return new WP_Error('mxchat_app_no_handler', __('The content generator is not loaded.', 'mxchat'), array('status' => 500));
        }
        $body = (array) $request->get_json_params();
        $post = $this->generated_post($body['post_id'] ?? 0);
        if (is_wp_error($post)) {
            return $post;
        }
        $args = array('ID' => $post->ID);
        if (isset($body['title']) && is_string($body['title'])) {
            $title = sanitize_text_field($body['title']);
            if ($title === '') {
                return new WP_Error('mxchat_app_no_title', __('The title cannot be empty.', 'mxchat'), array('status' => 400));
            }
            $args['post_title'] = $title;
        }
        if (isset($body['html']) && is_string($body['html'])) {
            $html = trim($body['html']);
            if (trim(wp_strip_all_tags($html)) === '') {
                return new WP_Error('mxchat_app_no_html', __('The article cannot be empty.', 'mxchat'), array('status' => 400));
            }
            $ai_css     = (string) get_post_meta($post->ID, '_mxchat_content_css', true);
            $fullwidth  = get_post_meta($post->ID, '_mxchat_fullwidth', true) === '1';
            $hide_title = get_post_meta($post->ID, '_mxchat_hide_title', true) === '1';
            $clean      = (string) $this->generator_call('sanitize_generated_html', array($html));
            $args['post_content'] = $this->generator_call('build_embedded_css', array($ai_css, $fullwidth, $hide_title)) . $clean;
        }
        if (count($args) === 1) {
            return new WP_Error('mxchat_app_nothing_to_save', __('Nothing to save.', 'mxchat'), array('status' => 400));
        }
        $result = wp_update_post(wp_slash($args), true);
        if (is_wp_error($result)) {
            return new WP_Error('mxchat_app_save_failed', $result->get_error_message(), array('status' => 500));
        }
        return rest_ensure_response(array('post_id' => (int) $post->ID, 'saved' => true, 'title' => get_the_title($post->ID)));
    }

    /**
     * Edit a generated post by instruction ("shorten the intro"): the Content screen's AI editor
     * (handle_content_edit) run after the reply has gone out, because a full rewrite can outlast a proxy's
     * timeout. The app polls /app/content/edit-status with the job key.
     */
    public function handle_content_edit($request) {
        $limited = self::rate_limit('content_edit');
        if (is_wp_error($limited)) {
            return $limited;
        }
        $generator = $this->content_generator();
        if (!$generator) {
            return new WP_Error('mxchat_app_no_handler', __('The content generator is not loaded.', 'mxchat'), array('status' => 500));
        }
        $body = (array) $request->get_json_params();
        $post = $this->generated_post($body['post_id'] ?? 0);
        if (is_wp_error($post)) {
            return $post;
        }
        $instruction = sanitize_textarea_field((string) ($body['instruction'] ?? ''));
        if (trim($instruction) === '') {
            return new WP_Error('mxchat_app_no_instruction', __('Say what to change.', 'mxchat'), array('status' => 400));
        }

        $job = 'mxapp_edit_' . $post->ID . '_' . time() . '_' . wp_generate_password(6, false, false);
        $this->set_job($job, array('state' => 'running', 'message' => __('Editing the article...', 'mxchat'), 'post_id' => (int) $post->ID, 'started' => time()));
        $this->reply_and_continue(array('job' => $job));

        $data = $this->run_admin_ajax(array($generator, 'handle_content_edit'), 'mxchat_content_nonce', 'nonce', array(
            'post_id'          => (int) $post->ID,
            'edit_instruction' => $instruction,
        ));
        if (is_wp_error($data)) {
            $this->set_job($job, array('state' => 'error', 'message' => $data->get_error_message(), 'post_id' => (int) $post->ID));
        } else {
            $this->set_job($job, array('state' => 'done', 'message' => __('Article updated.', 'mxchat'), 'post_id' => (int) $post->ID, 'title' => (string) ($data['data']['title'] ?? get_the_title($post->ID))));
        }
        exit;
    }

    public function handle_content_edit_status($request) {
        global $wpdb;
        $job = (string) $request->get_param('job');
        if (!preg_match('/^mxapp_edit_\d+_\d+_[A-Za-z0-9]+$/', $job)) {
            return new WP_Error('mxchat_app_bad_job', __('Unknown edit.', 'mxchat'), array('status' => 400));
        }
        // Read straight from the table, like the generator's progress: the worker is another PHP process.
        $raw  = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", '_mxchat_app_job_' . $job));
        $data = $raw === null ? null : maybe_unserialize($raw);
        if (!is_array($data)) {
            return rest_ensure_response(array('state' => 'unknown', 'message' => __('This edit is no longer tracked; reload the article.', 'mxchat')));
        }
        if ($data['state'] === 'running' && time() - (int) ($data['started'] ?? 0) > 10 * MINUTE_IN_SECONDS) {
            $data = array('state' => 'error', 'message' => __('The edit did not finish. Try a smaller change.', 'mxchat'), 'post_id' => (int) ($data['post_id'] ?? 0));
        }
        if ($data['state'] !== 'running') {
            delete_option('_mxchat_app_job_' . $job);
        }
        unset($data['started']);
        return rest_ensure_response($data);
    }

    private function set_job($job, array $data) {
        update_option('_mxchat_app_job_' . $job, $data, false);
    }

    public function handle_content_progress($request) {
        global $wpdb;
        $key = (string) $request->get_param('key');
        if (!preg_match('/^mxchat_content_progress_\d+_\d+$/', $key)) {
            return new WP_Error('mxchat_app_bad_key', __('Unknown progress key.', 'mxchat'), array('status' => 400));
        }
        // The generator writes progress straight to the options table (bypassing caches); read it the same way.
        $raw  = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", '_mxchat_progress_' . $key));
        $data = $raw === null ? null : maybe_unserialize($raw);
        if (!is_array($data)) {
            return rest_ensure_response(array('step' => 'starting', 'message' => __('Starting generation...', 'mxchat'), 'percent' => 0, 'done' => false, 'error' => null, 'result' => null));
        }
        $step   = (string) ($data['step'] ?? '');
        $error  = $step === 'error' ? (string) ($data['message'] ?? __('Generation failed.', 'mxchat')) : null;
        $result = null;
        if ($step === 'done' && !empty($data['result']) && is_array($data['result'])) {
            $r      = $data['result'];
            $result = array(
                'post_id'   => (int) ($r['post_id'] ?? 0),
                'title'     => (string) ($r['title'] ?? ''),
                'status'    => (string) ($r['status'] ?? ''),
                'permalink' => (string) ($r['permalink'] ?? ''),
                'edit_url'  => (string) ($r['edit_url'] ?? ''),
            );
        }
        return rest_ensure_response(array(
            'step'    => $step,
            'message' => (string) ($data['message'] ?? ''),
            'percent' => (int) ($data['percent'] ?? 0),
            'done'    => $step === 'done' || $step === 'error',
            'error'   => $error,
            'result'  => $result,
        ));
    }

    public function handle_content_history($request) {
        $generator = $this->content_generator();
        if (!$generator) {
            return new WP_Error('mxchat_app_no_handler', __('The content generator is not loaded.', 'mxchat'), array('status' => 500));
        }
        $data = $this->as_reader(function () use ($generator, $request) {
            return $this->run_admin_ajax(array($generator, 'handle_content_history'), 'mxchat_content_nonce', 'nonce', array(
                'page' => max(1, (int) $request->get_param('page')),
            ));
        });
        if (is_wp_error($data)) {
            return $data;
        }
        $payload = (array) ($data['data'] ?? array());
        $items   = array();
        foreach ((array) ($payload['items'] ?? array()) as $item) {
            $post_id = (int) ($item['post_id'] ?? 0);
            $post    = get_post($post_id);
            // Drafts have no GMT date yet (0000-00-00 00:00:00); convert the local one instead.
            $date_gmt = '';
            if ($post) {
                $date_gmt = $post->post_date_gmt !== '0000-00-00 00:00:00' ? (string) $post->post_date_gmt : (string) get_gmt_from_date($post->post_date);
            }
            $items[] = array(
                'post_id'   => $post_id,
                'title'     => (string) ($item['title'] ?? ''),
                'status'    => (string) ($item['status'] ?? ($post ? $post->post_status : '')),
                'post_type' => (string) ($item['post_type'] ?? ($post ? $post->post_type : 'post')),
                'date'      => $date_gmt,
                'permalink' => (string) ($item['permalink'] ?? get_permalink($post_id)),
                'edit_url'  => (string) get_edit_post_link($post_id, 'raw'),
                'thumbnail' => !empty($item['thumbnail']) ? (string) $item['thumbnail'] : null,
            );
        }
        return rest_ensure_response(array(
            'total'       => (int) ($payload['total'] ?? count($items)),
            'total_pages' => (int) ($payload['total_pages'] ?? 1),
            'items'       => $items,
        ));
    }

    public function handle_content_status($request) {
        $generator = $this->content_generator();
        if (!$generator) {
            return new WP_Error('mxchat_app_no_handler', __('The content generator is not loaded.', 'mxchat'), array('status' => 500));
        }
        $body   = (array) $request->get_json_params();
        $status = ($body['status'] ?? '') === 'publish' ? 'publish' : 'draft';
        $data   = $this->run_admin_ajax(array($generator, 'handle_update_post_status'), 'mxchat_content_nonce', 'nonce', array(
            'post_id'    => (int) ($body['post_id'] ?? 0),
            'new_status' => $status,
        ));
        if (is_wp_error($data)) {
            return $data;
        }
        return rest_ensure_response(array('post_id' => (int) ($data['data']['post_id'] ?? 0), 'status' => (string) ($data['data']['status'] ?? $status)));
    }

    public function handle_content_delete($request) {
        $generator = $this->content_generator();
        if (!$generator) {
            return new WP_Error('mxchat_app_no_handler', __('The content generator is not loaded.', 'mxchat'), array('status' => 500));
        }
        $body = (array) $request->get_json_params();
        $data = $this->run_admin_ajax(array($generator, 'handle_delete_content'), 'mxchat_content_nonce', 'nonce', array(
            'post_id' => (int) ($body['post_id'] ?? 0),
        ));
        if (is_wp_error($data)) {
            return $data;
        }
        return rest_ensure_response(array('post_id' => (int) ($data['data']['post_id'] ?? 0), 'deleted' => true));
    }

    // ------------------------------------------------------------------
    // Test the bot
    // ------------------------------------------------------------------

    /** The website's own chat handler, driven the way the Embed add-on drives it (class-mxchat-embed-api.php). */
    public function handle_chat($request) {
        $limited = self::rate_limit('chat');
        if (is_wp_error($limited)) {
            return $limited;
        }
        if (!class_exists('MxChat_Integrator')) {
            return new WP_Error('mxchat_app_no_handler', __('The chat handler is not loaded.', 'mxchat'), array('status' => 500));
        }
        $body       = (array) $request->get_json_params();
        $message    = sanitize_text_field((string) ($body['message'] ?? ''));
        $session_id = sanitize_text_field((string) ($body['session_id'] ?? ''));
        $bot_id     = $this->bot_id($body['bot_id'] ?? 'default');
        if ($message === '') {
            return new WP_Error('mxchat_app_no_message', __('Type a message.', 'mxchat'), array('status' => 400));
        }
        if (!preg_match('/^mxchat_app_[A-Za-z0-9_-]{6,64}$/', $session_id)) {
            return new WP_Error('mxchat_app_bad_session', __('session_id must look like mxchat_app_<id>.', 'mxchat'), array('status' => 400));
        }
        // The integrator ties a session to the identifier that opened it; the phone's address can change
        // between messages, so keep the owner in step (the Embed add-on does the same).
        if (class_exists('MxChat_User') && class_exists('MxChat_Session_Store')) {
            MxChat_Session_Store::set($session_id, 'owner', MxChat_User::mxchat_get_user_identifier());
        }

        $integrator = new MxChat_Integrator();
        $data       = $this->run_admin_ajax(array($integrator, 'mxchat_handle_chat_request'), 'mxchat_chat_nonce', 'nonce', array(
            'action'             => 'mxchat_handle_chat_request',
            'message'            => $message,
            'session_id'         => $session_id,
            'bot_id'             => $bot_id,
            'current_page_url'   => home_url('/'),
            'current_page_title' => 'MxChat app',
        ));
        if (is_wp_error($data)) {
            return $data;
        }
        $payload = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
        $reply   = (string) ($payload['text'] ?? $payload['message'] ?? $payload['response'] ?? '');
        if (!empty($payload['html']) && is_string($payload['html'])) {
            $reply .= ($reply === '' ? '' : "\n\n") . $payload['html'];
        }
        return rest_ensure_response(array(
            'reply'      => $reply,
            'session_id' => $session_id,
            'chat_mode'  => (string) ($payload['chat_mode'] ?? 'ai'),
        ));
    }
    // ------------------------------------------------------------------
    // Pairing and paired phones
    // ------------------------------------------------------------------

    public function handle_pair($request) {
        $body   = (array) $request->get_json_params();
        $result = MxChat_App_Auth::pair((string) ($body['code'] ?? ''), (string) ($body['device_name'] ?? ''), (string) ($body['platform'] ?? 'android'));
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function handle_devices($request) {
        $devices = array();
        foreach (MxChat_App_Auth::devices() as $device) {
            $row         = MxChat_App_Auth::public_device($device);
            $row['this'] = $row['id'] === $this->device_id;
            $devices[]   = $row;
        }
        return rest_ensure_response(array('devices' => $devices, 'this_device' => $this->device_id));
    }

    /** Revoke a paired phone; with no device_id, the phone making the request ("Disconnect this phone"). */
    public function handle_device_revoke($request) {
        $body = (array) $request->get_json_params();
        $id   = sanitize_text_field((string) ($body['device_id'] ?? $this->device_id));
        if ($id === '') {
            return new WP_Error('mxchat_app_no_device', __('This request is not from a paired phone.', 'mxchat'), array('status' => 400));
        }
        return rest_ensure_response(array('revoked' => MxChat_App_Auth::revoke($id), 'device_id' => $id));
    }
}
