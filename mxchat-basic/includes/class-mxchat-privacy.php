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
 *
 * Link-click rows (mxchat_url_clicks: user_ip + user_agent, plan 23c4a1) are
 * covered on both surfaces via the subject's session ids, since the table has
 * no email column of its own. Click rows whose session has no transcript left
 * (transcripts are retention-pruned; clicks were not) can never be tied to a
 * subject, so they are handled class-wide instead: user_ip is anonymized in
 * place (wp_privacy_anonymize_ip) and user_agent cleared after
 * CLICK_ANONYMIZE_DAYS, and whole rows are deleted after
 * CLICK_RETENTION_DAYS. The sweep is cron-independent — it rides the click
 * write path, time-gated and batched exactly like
 * MxChat_Session_Store::maybe_trim() — with the daily transcripts-cleanup
 * cron as a second chance on sites with no click traffic.
 */
class MxChat_Privacy {

    const EXPORT_SESSIONS_PER_PAGE = 10;
    const ERASE_SESSIONS_PER_PAGE  = 25;

    /** Days before a click row's user_ip is anonymized + user_agent cleared. Filterable. */
    const CLICK_ANONYMIZE_DAYS = 30;

    /** Days before a click row is deleted outright. Filterable. */
    const CLICK_RETENTION_DAYS = 365;

    /** Rows per sweep pass — capped so it can never slow a chat request. */
    const CLICK_SWEEP_BATCH = 200;

    /** Minimum seconds between write-path sweeps. */
    const CLICK_SWEEP_INTERVAL = HOUR_IN_SECONDS;

    /** Timestamp of the last click sweep. Autoloaded (tiny, read on click writes). */
    const CLICK_SWEEP_STAMP_OPTION = 'mxchat_url_clicks_last_sweep';

    /**
     * Anonymization bookmark: highest url_clicks id already anonymized. Ids
     * are auto-increment and click_timestamp is monotonic with them, so the
     * bookmark only ever moves forward — same idiom as the session-store
     * migration bookmark (b64b77). Non-autoloaded.
     */
    const CLICK_ANON_BOOKMARK_OPTION = 'mxchat_url_clicks_anon_bookmark';

    /**
     * Per-session wp_options keys (prefix . session_id). Mirrors the cleanup
     * set used by the transcripts delete paths, plus the session-owner key.
     */
    private static $session_option_prefixes = array(
        'mxchat_history_',
        'mxchat_lead_del_email_',
        'mxchat_lead_del_name_',
        'mxchat_lead_del_ts_',
        'mxchat_lead_del_consent_',
        'mxchat_lead_del_consent_label_',
        'mxchat_lead_del_consent_at_',
        // NOTE: mxchat_session_owner_ (b64b77) and mxchat_email_ /
        // mxchat_name_ / mxchat_agent_name_ (5658f2) moved out of wp_options
        // into the mxchat_sessions table. The eraser calls
        // MxChat_Session_Store::delete_session(), which drops the row AND the
        // legacy option keys, so they are no longer listed here.
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

        // Second chance for the click sweep on sites with no click traffic —
        // rides the existing daily transcripts-cleanup event rather than
        // scheduling its own. The write path remains the primary trigger
        // because WP-Cron cannot be relied on (see class docblock).
        add_action('mxchat_cleanup_old_transcripts', array(__CLASS__, 'run_url_click_maintenance'));
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

            // Lead-capture consent record (b062c4). If we hold a consent
            // decision about the subject, the export must show it — same
            // Article 15 parity rule as the click rows below.
            if (class_exists('MxChat_Session_Store')) {
                $consent_state = MxChat_Session_Store::get($sid, 'consent', '');
                if ($consent_state !== '' && $consent_state !== false) {
                    $data[] = array('name' => __('Lead-capture consent', 'mxchat'), 'value' => (string) $consent_state);
                    $consent_at = MxChat_Session_Store::get($sid, 'consent_at', '');
                    if (!empty($consent_at)) {
                        $data[] = array('name' => __('Consent recorded', 'mxchat'), 'value' => (string) $consent_at);
                    }
                    $consent_label = MxChat_Session_Store::get($sid, 'consent_label', '');
                    if (!empty($consent_label)) {
                        $data[] = array('name' => __('Consent text shown', 'mxchat'), 'value' => wp_strip_all_tags((string) $consent_label));
                    }
                }
            }

            foreach ($rows as $row) {
                $data[] = array(
                    'name'  => sprintf('[%s] %s', $row->timestamp, self::role_label($row->role)),
                    'value' => $row->message,
                );
            }

            // Link-click rows for this session (Article 15 parity with the
            // eraser — same subject, same session set, plan 23c4a1).
            if (self::url_clicks_table_exists()) {
                $clicks_table = $wpdb->prefix . 'mxchat_url_clicks';
                $clicks       = $wpdb->get_results($wpdb->prepare(
                    "SELECT clicked_url, click_timestamp, user_ip, user_agent
                     FROM {$clicks_table} WHERE session_id = %s
                     ORDER BY click_timestamp ASC, id ASC",
                    $sid
                ));
                foreach ($clicks as $click) {
                    $details = $click->clicked_url;
                    $meta    = array();
                    if (!empty($click->user_ip)) {
                        /* translators: %s: IP address recorded with a link click */
                        $meta[] = sprintf(__('IP address: %s', 'mxchat'), $click->user_ip);
                    }
                    if (!empty($click->user_agent)) {
                        /* translators: %s: browser user-agent recorded with a link click */
                        $meta[] = sprintf(__('Browser: %s', 'mxchat'), $click->user_agent);
                    }
                    if (!empty($meta)) {
                        $details .= ' (' . implode('; ', $meta) . ')';
                    }
                    $data[] = array(
                        'name'  => sprintf('[%s] %s', $click->click_timestamp, __('Link clicked', 'mxchat')),
                        'value' => $details,
                    );
                }
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

        // Link-click rows carry user_ip + user_agent — same delete the REST
        // session-cascade already runs, now reachable from an erasure request
        // (plan 23c4a1).
        if (self::url_clicks_table_exists()) {
            $wpdb->delete($wpdb->prefix . 'mxchat_url_clicks', array('session_id' => $sid), array('%s'));
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

        // Table-side twin of the option scan below (5658f2): orphan pre-chat
        // captures whose transcript rows are gone now live in the sessions
        // table's identity columns.
        if (class_exists('MxChat_Session_Store') && method_exists('MxChat_Session_Store', 'find_by_email')) {
            foreach (MxChat_Session_Store::find_by_email($email_address) as $sid) {
                MxChat_Session_Store::delete_session($sid);
                $removed++;
            }
        }

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
                delete_option('mxchat_lead_del_consent_' . $sid);
                delete_option('mxchat_lead_del_consent_label_' . $sid);
                delete_option('mxchat_lead_del_consent_at_' . $sid);
            } else {
                $sid = substr($opt->option_name, strlen('mxchat_email_'));
                delete_option('mxchat_email_' . $sid);
                delete_option('mxchat_name_' . $sid);
            }
            $removed++;
        }

        return $removed;
    }

    /* ---------------------------------------------------------------------
     * Link-click retention (plan 23c4a1)
     *
     * mxchat_url_clicks rows whose session outlived its transcript can never
     * be matched to a data subject, so the table needs its own lifecycle:
     * identifiers are anonymized after CLICK_ANONYMIZE_DAYS (the click
     * analytics — URL, time, session — stay useful) and rows are deleted
     * after CLICK_RETENTION_DAYS. Fired from the click write path
     * (time-gated, batched) plus the daily transcripts-cleanup cron.
     * ------------------------------------------------------------------ */

    private static function url_clicks_table_exists() {
        global $wpdb;
        static $exists = null;

        if ($exists === null) {
            $table  = $wpdb->prefix . 'mxchat_url_clicks';
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        }

        return $exists;
    }

    /**
     * Write-path entry point: at most one capped sweep per
     * CLICK_SWEEP_INTERVAL regardless of click traffic. Mirrors
     * MxChat_Session_Store::maybe_trim().
     */
    public static function maybe_sweep_url_clicks() {
        $last = (int) get_option(self::CLICK_SWEEP_STAMP_OPTION, 0);
        $now  = time();

        if ($last && ($now - $last) < self::CLICK_SWEEP_INTERVAL) {
            return 0;
        }

        // Stamp first: a slow sweep must not let concurrent clicks pile up
        // duplicate sweeps.
        update_option(self::CLICK_SWEEP_STAMP_OPTION, $now, 'yes');

        return self::sweep_url_clicks(self::CLICK_SWEEP_BATCH);
    }

    /** Daily cron: same sweep with a larger cap. */
    public static function run_url_click_maintenance() {
        return self::sweep_url_clicks(self::CLICK_SWEEP_BATCH * 10);
    }

    /**
     * One sweep pass: delete rows past retention, then anonymize identifiers
     * on rows past the anonymize window. Both capped at $limit rows.
     *
     * Anonymization progress is tracked by an id bookmark rather than by
     * inspecting the stored values — an anonymized IP is still a non-empty
     * string, so a value-based WHERE would re-match the same rows every pass
     * and never converge. click_timestamp is monotonic with the
     * auto-increment id (rows are only ever inserted at click time), so every
     * id at or below the bookmark is already processed.
     *
     * @return int Rows deleted + rows anonymized this pass.
     */
    public static function sweep_url_clicks($limit = self::CLICK_SWEEP_BATCH) {
        global $wpdb;

        if (!self::url_clicks_table_exists()) {
            return 0;
        }

        $table   = $wpdb->prefix . 'mxchat_url_clicks';
        $limit   = max(1, (int) $limit);
        $touched = 0;

        // click_timestamp is written via current_time('mysql', 1) — GMT — so
        // both cutoffs compare against GMT.

        /**
         * Days a link-click row is kept before deletion. 0 or negative
         * disables the delete sweep (rows are still anonymized below).
         *
         * @param int $days Retention window in days.
         */
        $retention_days = (int) apply_filters('mxchat_url_click_retention_days', self::CLICK_RETENTION_DAYS);
        if ($retention_days > 0) {
            $cutoff   = gmdate('Y-m-d H:i:s', time() - ($retention_days * DAY_IN_SECONDS));
            $touched += (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM `$table` WHERE click_timestamp < %s LIMIT %d",
                $cutoff,
                $limit
            ));
        }

        /**
         * Days before a link-click row's user_ip is anonymized in place
         * (wp_privacy_anonymize_ip) and its user_agent cleared. 0 or negative
         * disables anonymization.
         *
         * @param int $days Anonymization window in days.
         */
        $anon_days = (int) apply_filters('mxchat_url_click_anonymize_days', self::CLICK_ANONYMIZE_DAYS);
        if ($anon_days > 0) {
            $cutoff   = gmdate('Y-m-d H:i:s', time() - ($anon_days * DAY_IN_SECONDS));
            $bookmark = (int) get_option(self::CLICK_ANON_BOOKMARK_OPTION, 0);

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, user_ip FROM `$table`
                 WHERE id > %d AND click_timestamp < %s
                 ORDER BY id ASC LIMIT %d",
                $bookmark,
                $cutoff,
                $limit
            ));

            foreach ($rows as $row) {
                $anon_ip = '';
                if (is_string($row->user_ip) && $row->user_ip !== '') {
                    $anon_ip = wp_privacy_anonymize_ip($row->user_ip);
                }
                $wpdb->update(
                    $table,
                    array('user_ip' => $anon_ip, 'user_agent' => ''),
                    array('id' => (int) $row->id),
                    array('%s', '%s'),
                    array('%d')
                );
                $bookmark = (int) $row->id;
                $touched++;
            }

            if (!empty($rows)) {
                update_option(self::CLICK_ANON_BOOKMARK_OPTION, $bookmark, 'no');
            }
        }

        return $touched;
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
        $content .= '<p>' . __('When you click a link suggested in the chat, the clicked address is recorded together with your IP address and browser information. The IP address and browser information are anonymized after a limited period, and click records are deleted entirely after at most one year.', 'mxchat') . '</p>';
        $content .= '<p>' . __('Chat data is included in WordPress\'s personal data export and erasure tools: if you request a copy or deletion of your personal data from this site, your chat conversations are included in that request automatically.', 'mxchat') . '</p>';

        wp_add_privacy_policy_content(__('MxChat', 'mxchat'), wp_kses_post($content));
    }
}
