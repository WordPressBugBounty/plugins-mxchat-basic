<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * GDPR / privacy integration (plan-mxchat-20260801-b81e42).
 *
 * Registers MxChat with WordPress's native privacy tooling so a data-subject
 * request run from Tools → Export Personal Data / Erase Personal Data
 * automatically includes chat data. Three surfaces:
 *
 *  1. Exporter  — wp_privacy_personal_data_exporters
 *  2. Eraser    — wp_privacy_personal_data_erasers
 *  3. Suggested privacy-policy text — wp_add_privacy_policy_content
 *
 * Identity matching: transcripts key logged-in visitors by username
 * (user_identifier) and user_id, and store user_email when known — while the
 * privacy tools hand us only an email address. So a subject's rows are matched
 * three ways: user_email equals the address, and when the address resolves to
 * a registered account, user_id / user_identifier equal that account's id and
 * login. Matching only on email would silently miss logged-in conversations.
 */
class MxChat_Privacy {

    const EXPORT_SESSIONS_PER_PAGE = 10;
    const ERASE_SESSIONS_PER_PAGE  = 25;

    /**
     * Per-session wp_options keys (prefix . session_id). Mirrors the cleanup
     * set used by the transcripts delete paths, plus the session-owner key.
     */
    private static $session_option_prefixes = array(
        'mxchat_history_',
        'mxchat_email_',
        'mxchat_name_',
        'mxchat_agent_name_',
        'mxchat_lead_del_email_',
        'mxchat_lead_del_name_',
        'mxchat_lead_del_ts_',
        // NOTE: mxchat_session_owner_ moved out of wp_options into the
        // mxchat_sessions table (b64b77). The eraser now calls
        // MxChat_Session_Store::delete_session(), which drops the row AND the
        // legacy option keys, so it is no longer listed here.
    );

    /**
     * Per-session transients (prefix . session_id) set by the PDF / Word
     * upload chat flows. The *_url transients may point at an uploaded temp
     * file on disk — the eraser removes that file too.
     */
    private static $session_transient_prefixes = array(
        'mxchat_pdf_url_',
        'mxchat_pdf_filename_',
        'mxchat_pdf_embeddings_',
        'mxchat_include_pdf_in_context_',
        'mxchat_word_url_',
        'mxchat_word_filename_',
        'mxchat_word_embeddings_',
        'mxchat_include_word_in_context_',
    );

    public static function init() {
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'register_exporter'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'register_eraser'));
        add_action('admin_init', array(__CLASS__, 'add_privacy_policy_content'));
    }

    public static function register_exporter($exporters) {
        $exporters['mxchat-chat-transcripts'] = array(
            'exporter_friendly_name' => __('MxChat Chat Conversations', 'mxchat'),
            'callback'               => array(__CLASS__, 'export_personal_data'),
        );
        return $exporters;
    }

    public static function register_eraser($erasers) {
        $erasers['mxchat-chat-transcripts'] = array(
            'eraser_friendly_name' => __('MxChat Chat Conversations', 'mxchat'),
            'callback'             => array(__CLASS__, 'erase_personal_data'),
        );
        return $erasers;
    }

    /**
     * Build the WHERE fragment + params matching every transcript row that
     * belongs to the given email address.
     */
    private static function identity_where($email_address) {
        $where  = 'user_email = %s';
        $params = array($email_address);

        $user = get_user_by('email', $email_address);
        if ($user) {
            $where   .= ' OR user_id = %d OR user_identifier = %s';
            $params[] = (int) $user->ID;
            $params[] = $user->user_login;
        }

        return array($where, $params);
    }

    /**
     * Matching session ids in stable order (oldest conversation first).
     * $offset supports the exporter's offset pagination; the eraser always
     * passes 0 because deletion shrinks the matched set.
     */
    private static function get_session_ids($email_address, $limit, $offset) {
        global $wpdb;
        $table = $wpdb->prefix . 'mxchat_chat_transcripts';

        list($where, $params) = self::identity_where($email_address);
        $params[] = (int) $limit;
        $params[] = (int) $offset;

        return $wpdb->get_col($wpdb->prepare(
            "SELECT session_id FROM {$table} WHERE {$where}
             GROUP BY session_id
             ORDER BY MIN(timestamp) ASC, session_id ASC
             LIMIT %d OFFSET %d",
            $params
        ));
    }

    /**
     * Exporter callback. Called repeatedly by the privacy tool with an
     * incrementing $page until we report done. One export item per
     * conversation, with the messages as individual fields so the generated
     * report reads as a transcript rather than a flat wall.
     */
    public static function export_personal_data($email_address, $page = 1) {
        global $wpdb;
        $table = $wpdb->prefix . 'mxchat_chat_transcripts';

        $page   = max(1, (int) $page);
        $offset = ($page - 1) * self::EXPORT_SESSIONS_PER_PAGE;

        $session_ids = self::get_session_ids($email_address, self::EXPORT_SESSIONS_PER_PAGE, $offset);

        $items = array();
        foreach ($session_ids as $sid) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT role, message, user_email, user_name, user_identifier,
                        originating_page_url, timestamp
                 FROM {$table} WHERE session_id = %s
                 ORDER BY timestamp ASC, id ASC",
                $sid
            ));
            if (empty($rows)) {
                continue;
            }

            $emails      = array();
            $names       = array();
            $identifiers = array();
            $first_page  = '';
            foreach ($rows as $row) {
                if (!empty($row->user_email)) {
                    $emails[$row->user_email] = true;
                }
                if (!empty($row->user_name)) {
                    $names[$row->user_name] = true;
                }
                if (!empty($row->user_identifier)) {
                    $identifiers[$row->user_identifier] = true;
                }
                if ($first_page === '' && !empty($row->originating_page_url)) {
                    $first_page = $row->originating_page_url;
                }
            }

            $data = array(
                array('name' => __('Session ID', 'mxchat'), 'value' => $sid),
                array('name' => __('Conversation started', 'mxchat'), 'value' => $rows[0]->timestamp),
                array('name' => __('Last message', 'mxchat'), 'value' => end($rows)->timestamp),
            );
            if ($first_page !== '') {
                $data[] = array('name' => __('Started on page', 'mxchat'), 'value' => $first_page);
            }
            if (!empty($emails)) {
                $data[] = array('name' => __('Email on record', 'mxchat'), 'value' => implode(', ', array_keys($emails)));
            }
            if (!empty($names)) {
                $data[] = array('name' => __('Name on record', 'mxchat'), 'value' => implode(', ', array_keys($names)));
            }
            if (!empty($identifiers)) {
                $data[] = array('name' => __('Visitor identifier', 'mxchat'), 'value' => implode(', ', array_keys($identifiers)));
            }

            foreach ($rows as $row) {
                $data[] = array(
                    'name'  => sprintf('[%s] %s', $row->timestamp, self::role_label($row->role)),
                    'value' => $row->message,
                );
            }

            $items[] = array(
                'group_id'          => 'mxchat-chat-transcripts',
                'group_label'       => __('Chat Conversations (MxChat)', 'mxchat'),
                'group_description' => __('Chatbot conversations recorded by the MxChat plugin.', 'mxchat'),
                'item_id'           => 'mxchat-session-' . $sid,
                'data'              => $data,
            );
        }

        return array(
            'data' => $items,
            'done' => count($session_ids) < self::EXPORT_SESSIONS_PER_PAGE,
        );
    }

    private static function role_label($role) {
        switch ($role) {
            case 'user':
                return __('Visitor', 'mxchat');
            case 'bot':
            case 'assistant':
                return __('Chatbot', 'mxchat');
            case 'agent':
                return __('Agent', 'mxchat');
            default:
                return ucfirst((string) $role);
        }
    }

    /**
     * Eraser callback. Deletes the subject's conversations outright — a chat
     * transcript has no ordinary legitimate-interest basis for retention after
     * an erasure request, and half-anonymised free text routinely still
     * contains identifying detail the person typed.
     *
     * Pagination note: we always query at offset 0 because deleting shrinks
     * the matched set (offset paging would skip sessions). A site may retain a
     * specific session via the mxchat_privacy_erase_session filter; retained
     * sessions are reported via items_retained with a reason. If an entire
     * batch is retained we report done rather than loop forever — with the
     * side effect that a filter retaining 25+ consecutive sessions defers the
     * rest to a re-run of the tool.
     */
    public static function erase_personal_data($email_address, $page = 1) {
        global $wpdb;

        $items_removed  = 0;
        $items_retained = 0;
        $messages       = array();

        $session_ids = self::get_session_ids($email_address, self::ERASE_SESSIONS_PER_PAGE, 0);

        $deleted_this_batch = 0;
        foreach ($session_ids as $sid) {
            /**
             * Allow a site to retain a specific conversation (e.g. a legal
             * hold). Default true = erase. Retained sessions are reported,
             * never silently kept.
             *
             * @param bool   $erase Whether to erase this conversation.
             * @param string $sid   The session id.
             * @param string $email_address The data subject's email.
             */
            $erase = apply_filters('mxchat_privacy_erase_session', true, $sid, $email_address);
            if (!$erase) {
                $items_retained++;
                $messages[] = sprintf(
                    /* translators: %s: chat session id */
                    __('Chat conversation %s was retained by a site-specific policy (mxchat_privacy_erase_session filter).', 'mxchat'),
                    $sid
                );
                continue;
            }

            self::erase_session($sid);
            $items_removed++;
            $deleted_this_batch++;
        }

        $done = (count($session_ids) < self::ERASE_SESSIONS_PER_PAGE) || ($deleted_this_batch === 0);

        if ($done) {
            $items_removed += self::erase_lingering_options($email_address);
            wp_cache_delete('all_chat_sessions', 'mxchat_chat_sessions');
        }

        return array(
            'items_removed'  => $items_removed,
            'items_retained' => $items_retained,
            'messages'       => $messages,
            'done'           => $done,
        );
    }

    /**
     * Remove one conversation and every piece of derived state: transcript
     * rows, translation rows, per-session options (history, pre-chat capture,
     * chat-deleted lead preservation, agent name, session owner), per-session
     * upload transients, any uploaded PDF/Word temp file those transients
     * still point at, and the session's row cache.
     */
    private static function erase_session($sid) {
        global $wpdb;
        static $has_translations = null;

        $table              = $wpdb->prefix . 'mxchat_chat_transcripts';
        $translations_table = $wpdb->prefix . 'mxchat_transcript_translations';
        if ($has_translations === null) {
            $has_translations = $wpdb->get_var("SHOW TABLES LIKE '$translations_table'") === $translations_table;
        }

        // Uploaded files first, while the *_url transients still map the
        // session to its randomised temp filename. Only ever delete a real
        // local file that follows the plugin's own mxchat_* naming — the PDF
        // transient can also hold a remote URL, which file_exists rejects.
        foreach (array('mxchat_pdf_url_', 'mxchat_word_url_') as $prefix) {
            $path = get_transient($prefix . $sid);
            if (is_string($path) && $path !== ''
                && strpos(basename($path), 'mxchat_') === 0
                && @file_exists($path) && @is_file($path)) {
                wp_delete_file($path);
            }
        }

        foreach (self::$session_transient_prefixes as $prefix) {
            delete_transient($prefix . $sid);
        }

        $wpdb->delete($table, array('session_id' => $sid), array('%s'));
        if ($has_translations) {
            $wpdb->delete($translations_table, array('session_id' => $sid), array('%s'));
        }

        foreach (self::$session_option_prefixes as $prefix) {
            delete_option($prefix . $sid);
        }

        // Per-session state (owner, originating page, mode, channel) — one row,
        // plus the legacy option keys on installs still mid-migration (b64b77).
        if (class_exists('MxChat_Session_Store')) {
            MxChat_Session_Store::delete_session($sid);
        }

        wp_cache_delete('chat_session_' . $sid, 'mxchat_chat_sessions');
    }

    /**
     * Orphan sweep: pre-chat captures and chat-deleted lead preservations
     * whose stored value is the subject's email but whose transcript rows are
     * already gone (e.g. removed earlier by retention). Mirrors the tail of
     * MxChat_Admin::mxchat_wipe_leads_by_email(). Returns entries removed.
     */
    private static function erase_lingering_options($email_address) {
        global $wpdb;

        $removed  = 0;
        $email_lc = strtolower(trim($email_address));

        $lingering = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE 'mxchat\\_email\\_%' OR option_name LIKE 'mxchat\\_lead\\_del\\_email\\_%'"
        );
        foreach ($lingering as $opt) {
            if (strtolower(trim((string) $opt->option_value)) !== $email_lc) {
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
            $removed++;
        }

        return $removed;
    }

    /**
     * Suggested privacy-policy text, surfaced in the WP privacy-policy editor
     * (Settings → Privacy → Policy Guide) for the site owner to adopt.
     */
    public static function add_privacy_policy_content() {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content  = '<p>' . __('This site uses MxChat to provide an AI chat assistant. When you use the chat, your messages and the assistant\'s replies are stored in this site\'s own database — they are not stored by a third-party chat service. If you provide your name or email address in the chat, those are stored with the conversation. Logged-in visitors are identified by their username; anonymous visitors are identified by their IP address. The page on which the conversation started is also recorded.', 'mxchat') . '</p>';
        $content .= '<p>' . __('Chat conversations are kept according to the retention period configured by the site administrator, and may be deleted automatically after that period. Documents uploaded to the chat for analysis are processed on this server and their temporary copies expire automatically.', 'mxchat') . '</p>';
        $content .= '<p>' . __('Chat data is included in WordPress\'s personal data export and erasure tools: if you request a copy or deletion of your personal data from this site, your chat conversations are included in that request automatically.', 'mxchat') . '</p>';

        wp_add_privacy_policy_content(__('MxChat', 'mxchat'), wp_kses_post($content));
    }
}
