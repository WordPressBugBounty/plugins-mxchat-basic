<?php
/**
 * Per-session chat state storage.
 *
 * Historically every piece of per-session state was written to wp_options
 * under a session-suffixed key (mxchat_session_owner_<sid>, mxchat_mode_<sid>,
 * ...). Those rows have no expiry and no index, so the table grew linearly
 * with sessions forever — on a real install MxChat owned 65% of wp_options
 * (6,858 of 10,538 rows), and several hundred of them were autoloaded because
 * the writes omitted the autoload argument. wp_options is the one table
 * WordPress reads in full on every request, including anonymous front-end
 * views.
 *
 * This class replaces those rows with one indexed row per session in a
 * dedicated table, plus a retention sweep that does not depend on WP-Cron
 * (WP-Cron is disabled on some installs; see plan-mxchat-20260805-b64b77).
 *
 * Fields owned by this store: owner, originating_page, mode, channel, plus
 * (since schema v2 / plan 5658f2) the visitor identity keys name, email and
 * agent_name — moved atomically with the mxchat-forms and mxchat-embed
 * writers so no plugin half writes options while another reads the table.
 * Chat history is deliberately NOT here either: plan 839c4c deduplicated the
 * mxchat_history_ options into the transcripts table (their content was a
 * second copy of it all along) — MxChat_Utils::get_session_history() is the
 * accessor.
 *
 * @package MxChat
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Session_Store {

    /** Table name without the wpdb prefix. */
    const TABLE_SUFFIX = 'mxchat_sessions';

    /**
     * Bump when create_table()'s schema changes. ensure_table() compares the
     * READY option against this, so existing installs re-run dbDelta once and
     * pick up new columns ('1' = b64b77 original, '2' = 5658f2 identity
     * columns, '3' = b062c4 consent record).
     */
    const SCHEMA_VERSION = '3';

    /**
     * Bump when $legacy_prefixes gains entries. A stale version in the stored
     * migration state resets the bookmark so the new prefixes get a full
     * re-scan (the migration upsert is a no-op on already-moved rows).
     */
    const MIGRATION_VERSION = 2;

    /** Batched-migration bookmark. Non-autoloaded. */
    const MIGRATION_OPTION = 'mxchat_session_store_migration';

    /** Timestamp of the last retention sweep. Autoloaded (tiny, read on writes). */
    const TRIM_STAMP_OPTION = 'mxchat_session_store_last_trim';

    /** Set once the table is known to exist, so the hot path costs no SHOW TABLES. */
    const READY_OPTION = 'mxchat_session_store_ready';

    /** Daily maintenance event. Belt to the opportunistic braces. */
    const CRON_HOOK = 'mxchat_session_store_maintenance';

    /** Default retention for untouched sessions, in days. Filterable. */
    const DEFAULT_RETENTION_DAYS = 30;

    /** Rows migrated per batch. */
    const MIGRATION_BATCH = 500;

    /** Rows deleted per opportunistic sweep. */
    const TRIM_BATCH = 200;

    /** Minimum seconds between opportunistic sweeps. */
    const TRIM_INTERVAL = HOUR_IN_SECONDS;

    /**
     * Logical field => column name. Also the allowlist: anything not in here
     * is rejected, so the column name is never attacker-influenced.
     */
    private static $columns = array(
        'owner'            => 'owner',
        'originating_page' => 'originating_page',
        'mode'             => 'mode',
        'channel'          => 'channel',
        'name'             => 'visitor_name',
        'email'            => 'visitor_email',
        'agent_name'       => 'agent_name',
        // Consent record (b062c4). Never lived in wp_options, so no legacy
        // prefixes: legacy_get() correctly falls through to the default.
        'consent'          => 'consent_given',
        'consent_label'    => 'consent_label',
        'consent_at'       => 'consent_recorded_at',
    );

    /** Logical field => the wp_options prefix it used to live under. */
    private static $legacy_prefixes = array(
        'owner'            => 'mxchat_session_owner_',
        'originating_page' => 'mxchat_originating_page_',
        'mode'             => 'mxchat_mode_',
        'channel'          => 'mxchat_channel_',
        'name'             => 'mxchat_name_',
        'email'            => 'mxchat_email_',
        'agent_name'       => 'mxchat_agent_name_',
    );

    /** Fields stored as JSON rather than a scalar. */
    private static $json_fields = array('originating_page');

    /** Request-scoped row cache. Mirrors get_option()'s per-request caching. */
    private static $cache = array();

    /** Set once per request after the table has been confirmed/created. */
    private static $table_ready = false;

    public static function init() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_maintenance'));

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }

        // Cron-independent progress: one migration batch per admin PAGE load
        // until the backlog is drained. Installs with WP-Cron disabled still
        // converge, just more slowly.
        //
        // wp_doing_ajax() guard is load-bearing, not defensive: admin-ajax.php
        // fires admin_init too, and the chat widget's own message endpoint is
        // an admin-ajax action. Without this, an anonymous visitor sending a
        // chat message would pay for a 500-row migration batch inside their
        // request. Measured on the test site: the front end alone never
        // advanced the bookmark, one admin-ajax hit drained the whole backlog.
        if (!wp_doing_ajax()) {
            add_action('admin_init', array(__CLASS__, 'maybe_migrate'), 20);
        }
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /* ---------------------------------------------------------------------
     * Schema
     * ------------------------------------------------------------------ */

    public static function create_table() {
        global $wpdb;

        $table           = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        // session_id is the primary key so set() can use a single upsert.
        // 191 chars keeps the key inside the utf8mb4 index limit; session ids
        // are 'mxchat_chat_' + 32 hex, and sanitize_session_id() caps at 128.
        $sql = "CREATE TABLE $table (
            session_id varchar(191) NOT NULL,
            owner varchar(191) DEFAULT NULL,
            originating_page longtext DEFAULT NULL,
            mode varchar(32) DEFAULT NULL,
            channel varchar(191) DEFAULT NULL,
            visitor_name varchar(191) DEFAULT NULL,
            visitor_email varchar(191) DEFAULT NULL,
            agent_name varchar(191) DEFAULT NULL,
            consent_given varchar(8) DEFAULT NULL,
            consent_label text DEFAULT NULL,
            consent_recorded_at datetime DEFAULT NULL,
            created_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY  (session_id),
            KEY updated_at (updated_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if ($exists) {
            update_option(self::READY_OPTION, self::SCHEMA_VERSION, 'yes');
            self::$table_ready = true;
        }

        return $exists;
    }

    /**
     * Cheap guard so a read/write never fires at a missing table. Steady state
     * is one autoloaded option read (free — it is already in alloptions).
     */
    private static function ensure_table() {
        if (self::$table_ready) {
            return true;
        }

        // The stored value is the schema version the table was last built at.
        // Anything else (including b64b77's '1') re-runs create_table() once —
        // dbDelta adds the missing columns to existing installs.
        if (get_option(self::READY_OPTION) === self::SCHEMA_VERSION) {
            self::$table_ready = true;
            return true;
        }

        return self::create_table();
    }

    /**
     * Feature probe for add-ons: does this core's store handle $field?
     * mxchat-forms / mxchat-embed call this before routing identity writes to
     * the table, falling back to the legacy option write against an older
     * core whose allowlist would silently reject the field.
     */
    public static function supports($field) {
        return isset(self::$columns[$field]);
    }

    /* ---------------------------------------------------------------------
     * Accessors
     * ------------------------------------------------------------------ */

    /**
     * @param string $session_id
     * @param string $field   One of the keys in self::$columns.
     * @param mixed  $default Returned when the session or the field is unset —
     *                        matching get_option()'s default semantics.
     * @return mixed
     */
    public static function get($session_id, $field, $default = false) {
        $session_id = self::sanitize($session_id);
        if ($session_id === '' || !isset(self::$columns[$field])) {
            return $default;
        }

        $row = self::row($session_id);
        if (!is_array($row)) {
            return self::legacy_get($session_id, $field, $default);
        }

        $column = self::$columns[$field];
        if (!array_key_exists($column, $row) || $row[$column] === null) {
            return self::legacy_get($session_id, $field, $default);
        }

        if (in_array($field, self::$json_fields, true)) {
            $decoded = json_decode($row[$column], true);
            return is_array($decoded) ? $decoded : $default;
        }

        return $row[$column];
    }

    /**
     * Mid-migration read-through. A session created before the update may
     * still live only in wp_options until its batch migrates; without this,
     * an in-flight live-agent session read mode='ai' (dropping the visitor
     * out of agent mode) and a handed-off session read channel='' (minting a
     * duplicate Slack channel on re-handover) — plan 71e4b6. Gated on
     * migration state so steady-state reads never pay for it: once the
     * migration has marked itself done this is a single cached option read.
     *
     * get_option() returns the legacy value in its historical shape (options
     * were stored unserialized), which is exactly what pre-b64b77 callers got.
     */
    private static function legacy_get($session_id, $field, $default) {
        if (!isset(self::$legacy_prefixes[$field]) || self::is_migrated()) {
            return $default;
        }

        return get_option(self::$legacy_prefixes[$field] . $session_id, $default);
    }

    /**
     * Upsert one field. Touches updated_at, which is what the retention sweep
     * ages against.
     *
     * @return bool True when the write was attempted against a live table.
     */
    public static function set($session_id, $field, $value) {
        global $wpdb;

        $session_id = self::sanitize($session_id);
        if ($session_id === '' || !isset(self::$columns[$field]) || !self::ensure_table()) {
            return false;
        }

        if (in_array($field, self::$json_fields, true)) {
            $stored = is_null($value) ? null : wp_json_encode($value);
        } else {
            $stored = is_scalar($value) ? (string) $value : wp_json_encode($value);
        }

        $table  = self::table();
        $column = self::$columns[$field]; // allowlisted above, never user input
        $now    = current_time('mysql');

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `$table` (session_id, `$column`, created_at, updated_at)
                 VALUES (%s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE `$column` = VALUES(`$column`), updated_at = VALUES(updated_at)",
                $session_id,
                $stored,
                $now,
                $now
            )
        );

        unset(self::$cache[$session_id]);
        self::maybe_trim();

        return true;
    }

    /** Clear one field, leaving the rest of the session row intact. */
    public static function delete($session_id, $field) {
        global $wpdb;

        $session_id = self::sanitize($session_id);
        if ($session_id === '' || !isset(self::$columns[$field]) || !self::ensure_table()) {
            return false;
        }

        $table  = self::table();
        $column = self::$columns[$field];

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `$table` SET `$column` = NULL, updated_at = %s WHERE session_id = %s",
                current_time('mysql'),
                $session_id
            )
        );

        // Mirror delete_session(): an unmigrated legacy row left behind would
        // be resurrected by a later migration batch — e.g. an archived Slack
        // channel id coming back from the dead (plan 71e4b6). The migration
        // upsert COALESCEs, so a NULLed column is exactly the fillable case.
        if (isset(self::$legacy_prefixes[$field])) {
            delete_option(self::$legacy_prefixes[$field] . $session_id);
        }

        unset(self::$cache[$session_id]);

        return true;
    }

    /**
     * Drop every field for a session. This is what session-clear and the GDPR
     * eraser want — the old code deleted mode/channel by hand and silently
     * left owner and originating_page behind forever, which is a large part of
     * why those two prefixes dominated the options table.
     */
    public static function delete_session($session_id) {
        global $wpdb;

        $session_id = self::sanitize($session_id);
        if ($session_id === '' || !self::ensure_table()) {
            return false;
        }

        $wpdb->delete(self::table(), array('session_id' => $session_id), array('%s'));
        unset(self::$cache[$session_id]);

        // Belt: an install part-way through migration may still hold the legacy
        // rows for this session. Clearing a session must clear both homes.
        foreach (self::$legacy_prefixes as $prefix) {
            delete_option($prefix . $session_id);
        }

        // d0cae1: integrations holding per-session state outside WP purge on
        // this. Fires on every ended session — the retention sweep in trim()
        // fires it too with reason 'retention'; an integration that only
        // listens to one path leaks state on the other.
        do_action('mxchat_session_ended', $session_id, array('reason' => 'deleted'));

        return true;
    }

    /**
     * Reverse lookup: which session owns this Slack channel? The inbound
     * Slack Events webhook resolves agent replies this way — before b64b77 it
     * queried wp_options for mxchat_channel_ rows, which this store migrates
     * away and deletes, so the webhook MUST read the table instead
     * (plan 71e4b6). Newest-updated wins if a channel id was ever reused.
     *
     * @param string $channel_id
     * @return string Session id, or '' when no session owns the channel.
     */
    public static function find_by_channel($channel_id) {
        global $wpdb;

        $channel_id = is_scalar($channel_id) ? trim((string) $channel_id) : '';
        if ($channel_id === '' || !self::ensure_table()) {
            return '';
        }

        $table = self::table();

        $session_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT session_id FROM `$table` WHERE channel = %s ORDER BY updated_at DESC LIMIT 1",
                $channel_id
            )
        );

        return is_string($session_id) ? $session_id : '';
    }

    /**
     * Sessions whose stored visitor email matches (collation-insensitive, same
     * as the option sweeps' strtolower compare). The lead-wipe and GDPR
     * lingering sweeps use this as the table-side twin of their
     * "option_name LIKE 'mxchat_email_%'" scans (5658f2).
     *
     * @param string $email
     * @return string[] Session ids, possibly empty.
     */
    public static function find_by_email($email) {
        global $wpdb;

        $email = is_scalar($email) ? trim((string) $email) : '';
        if ($email === '' || !self::ensure_table()) {
            return array();
        }

        $table = self::table();

        $sids = $wpdb->get_col(
            $wpdb->prepare("SELECT session_id FROM `$table` WHERE visitor_email = %s", $email)
        );

        return is_array($sids) ? $sids : array();
    }

    /**
     * Every session row holding a visitor email — the table-side source for
     * the Leads tab's orphan bucket (pre-chat captures with no transcript
     * rows). Bounded by lead count, not session count: identity columns are
     * only ever set by the capture paths.
     *
     * @return array[] Rows of [session_id, visitor_email, visitor_name].
     */
    public static function identity_rows() {
        global $wpdb;

        if (!self::ensure_table()) {
            return array();
        }

        $table = self::table();

        $rows = $wpdb->get_results(
            "SELECT session_id, visitor_email, visitor_name FROM `$table`
             WHERE visitor_email IS NOT NULL AND visitor_email != ''",
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * Record the lead-capture consent decision in one atomic upsert (b062c4).
     *
     * Three facts travel together — whether the box was ticked, when, and the
     * exact label text the visitor saw — because a "yes" without "to what"
     * proves nothing once the owner rewrites the label. One query rather than
     * three set() calls so a crash can never store half a consent record.
     *
     * Rows written here always also carry visitor_email (the capture endpoint
     * stores the email in the same request), so the retention sweep's identity
     * guard keeps consent records exactly as long as it keeps the lead.
     *
     * @param string $session_id
     * @param bool   $given True when the box was ticked.
     * @param string $label The sanitized label markup as rendered to the visitor.
     * @return bool True when the write was attempted against a live table.
     */
    public static function record_consent($session_id, $given, $label) {
        global $wpdb;

        $session_id = self::sanitize($session_id);
        if ($session_id === '' || !self::ensure_table()) {
            return false;
        }

        $table = self::table();
        $now   = current_time('mysql');

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `$table` (session_id, consent_given, consent_label, consent_recorded_at, created_at, updated_at)
                 VALUES (%s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    consent_given = VALUES(consent_given),
                    consent_label = VALUES(consent_label),
                    consent_recorded_at = VALUES(consent_recorded_at),
                    updated_at = VALUES(updated_at)",
                $session_id,
                $given ? 'yes' : 'no',
                (string) $label,
                $now,
                $now,
                $now
            )
        );

        unset(self::$cache[$session_id]);
        self::maybe_trim();

        return true;
    }

    /**
     * Newest recorded consent for a lead email, across all their sessions.
     * The Leads tab and CSV export read this — a lead is aggregated by email
     * while consent is captured per session, so "the lead's consent state" is
     * the most recent record. NULL result means NOT RECORDED, which the
     * callers must render as exactly that ("not recorded" and "no consent"
     * are different facts).
     *
     * @param string $email
     * @return array|null ['given' => 'yes'|'no', 'label' => string, 'recorded_at' => string] or null.
     */
    public static function latest_consent($email) {
        global $wpdb;

        $email = is_scalar($email) ? trim((string) $email) : '';
        if ($email === '' || !self::ensure_table()) {
            return null;
        }

        $table = self::table();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT consent_given, consent_label, consent_recorded_at
                 FROM `$table`
                 WHERE visitor_email = %s AND consent_given IS NOT NULL AND consent_given != ''
                 ORDER BY consent_recorded_at DESC LIMIT 1",
                $email
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        return array(
            'given'       => (string) $row['consent_given'],
            'label'       => is_string($row['consent_label']) ? $row['consent_label'] : '',
            'recorded_at' => is_string($row['consent_recorded_at']) ? $row['consent_recorded_at'] : '',
        );
    }

    private static function row($session_id) {
        if (array_key_exists($session_id, self::$cache)) {
            return self::$cache[$session_id];
        }

        if (!self::ensure_table()) {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `$table` WHERE session_id = %s", $session_id),
            ARRAY_A
        );

        self::$cache[$session_id] = is_array($row) ? $row : false;

        return self::$cache[$session_id];
    }

    /** Test seam — the request cache is otherwise invisible to callers. */
    public static function flush_cache() {
        self::$cache = array();
    }

    private static function sanitize($session_id) {
        if (class_exists('MxChat_Utils')) {
            return MxChat_Utils::sanitize_session_id($session_id);
        }

        $session_id = is_scalar($session_id) ? trim((string) $session_id) : '';

        return preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $session_id) ? $session_id : '';
    }

    /* ---------------------------------------------------------------------
     * Migration
     * ------------------------------------------------------------------ */

    public static function migration_state() {
        $state = get_option(self::MIGRATION_OPTION, array());
        if (!is_array($state)) {
            $state = array();
        }

        $state = wp_parse_args(
            $state,
            array(
                'done'           => false,
                'last_option_id' => 0,
                'moved'          => 0,
                'sessions'       => 0,
                'version'        => 1, // states written before versioning are b64b77's v1
            )
        );

        // A version bump means $legacy_prefixes gained entries after this
        // install finished (or started) migrating — reset the bookmark so the
        // new prefixes get a full pass. Re-processing already-moved rows is a
        // no-op (COALESCE upsert), so the re-scan is safe. The reset lives
        // here rather than in migrate_batch() so is_migrated() flips false at
        // the same moment, which re-arms the legacy_get() read-through for
        // rows the re-scan has not reached yet.
        if ((int) $state['version'] !== self::MIGRATION_VERSION) {
            $state['version']        = self::MIGRATION_VERSION;
            $state['done']           = false;
            $state['last_option_id'] = 0;
        }

        return $state;
    }

    public static function is_migrated() {
        $state = self::migration_state();
        return !empty($state['done']);
    }

    /** One batch per call. Safe to call on any request; no-ops once drained. */
    public static function maybe_migrate() {
        if (self::is_migrated()) {
            return 0;
        }

        return self::migrate_batch(self::MIGRATION_BATCH);
    }

    /**
     * Move one batch of legacy option rows into the sessions table.
     *
     * Idempotent: the upsert makes a re-run of the same rows a no-op, and the
     * option_id bookmark only moves forward. A crash mid-batch re-processes at
     * worst one batch.
     *
     * @return int Number of option rows folded in this batch.
     */
    public static function migrate_batch($batch = self::MIGRATION_BATCH) {
        global $wpdb;

        if (!self::ensure_table()) {
            return 0;
        }

        $state = self::migration_state();
        if (!empty($state['done'])) {
            return 0;
        }

        $batch = max(1, (int) $batch);

        $likes  = array();
        $params = array((int) $state['last_option_id']);
        foreach (self::$legacy_prefixes as $prefix) {
            $likes[]  = 'option_name LIKE %s';
            $params[] = $wpdb->esc_like($prefix) . '%';
        }
        $params[] = $batch;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_id, option_name, option_value
                 FROM {$wpdb->options}
                 WHERE option_id > %d AND (" . implode(' OR ', $likes) . ")
                 ORDER BY option_id ASC
                 LIMIT %d",
                $params
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            $state['done'] = true;
            update_option(self::MIGRATION_OPTION, $state, 'no');
            return 0;
        }

        $moved     = 0;
        $sessions  = array();
        $to_delete = array();

        foreach ($rows as $row) {
            $state['last_option_id'] = max((int) $state['last_option_id'], (int) $row['option_id']);

            $field = null;
            $sid   = '';
            foreach (self::$legacy_prefixes as $candidate => $prefix) {
                if (strpos($row['option_name'], $prefix) === 0) {
                    $field = $candidate;
                    $sid   = self::sanitize(substr($row['option_name'], strlen($prefix)));
                    break;
                }
            }

            // Junk suffix (not a valid session id) — leave the row alone rather
            // than guess. It is not ours to delete.
            if ($field === null || $sid === '') {
                continue;
            }

            $value = maybe_unserialize($row['option_value']);

            if (in_array($field, self::$json_fields, true)) {
                $stored = is_null($value) ? null : wp_json_encode($value);
            } else {
                $stored = is_scalar($value) ? (string) $value : wp_json_encode($value);
            }

            $sessions[$sid] = true;
            $to_delete[]    = $row['option_name'];

            self::upsert_raw($sid, self::$columns[$field], $stored);
            $moved++;
        }

        // delete_option() rather than a bulk DELETE: it clears the object cache
        // and the notoptions cache, which a raw query would leave stale.
        foreach ($to_delete as $option_name) {
            delete_option($option_name);
        }

        $state['moved']    = (int) $state['moved'] + $moved;
        $state['sessions'] = (int) $state['sessions'] + count($sessions);

        if (count($rows) < $batch) {
            $state['done'] = true;
        }

        update_option(self::MIGRATION_OPTION, $state, 'no');
        self::flush_cache();

        return $moved;
    }

    /**
     * Upsert used by the migration. Preserves the legacy row's own timestamps
     * as far as we can — options carry none, so migrated sessions start their
     * retention clock now rather than being swept on the first pass.
     *
     * COALESCE, not plain VALUES(): migration only FILLS missing values and
     * never overwrites one the live code wrote after the update. A plain
     * upsert let a stale legacy row clobber newer state — e.g. re-arm
     * mode='agent' on a session the agent had already ended (plan 71e4b6).
     * The normal set() upsert stays last-write-wins; that is correct there.
     */
    private static function upsert_raw($session_id, $column, $value) {
        global $wpdb;

        $table = self::table();
        $now   = current_time('mysql');

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `$table` (session_id, `$column`, created_at, updated_at)
                 VALUES (%s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE `$column` = COALESCE(`$column`, VALUES(`$column`)), updated_at = VALUES(updated_at)",
                $session_id,
                $value,
                $now,
                $now
            )
        );
    }

    /* ---------------------------------------------------------------------
     * Retention
     * ------------------------------------------------------------------ */

    public static function retention_days() {
        $days = (int) apply_filters('mxchat_session_retention_days', self::DEFAULT_RETENTION_DAYS);

        // 0 or negative would sweep live sessions; refuse it.
        return $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * Time-gated sweep fired from set(). The spec calls for cleanup that
     * survives WP-Cron never running, so the write path carries it: at most
     * one capped delete per TRIM_INTERVAL regardless of traffic.
     */
    public static function maybe_trim() {
        $last = (int) get_option(self::TRIM_STAMP_OPTION, 0);
        $now  = time();

        if ($last && ($now - $last) < self::TRIM_INTERVAL) {
            return 0;
        }

        // Stamp first: a slow delete must not let concurrent writes pile up
        // duplicate sweeps.
        update_option(self::TRIM_STAMP_OPTION, $now, 'yes');

        return self::trim(self::TRIM_BATCH);
    }

    /**
     * Delete up to $limit sessions untouched for longer than the retention
     * window. Capped so it can never turn a chat request into a long delete.
     *
     * @return int Rows deleted.
     */
    public static function trim($limit = self::TRIM_BATCH) {
        global $wpdb;

        if (!self::ensure_table()) {
            return 0;
        }

        $table  = self::table();
        $limit  = max(1, (int) $limit);
        $cutoff = gmdate('Y-m-d H:i:s', time() - (self::retention_days() * DAY_IN_SECONDS));

        // current_time('mysql') writes site-local time, so compare against a
        // site-local cutoff.
        $cutoff = get_date_from_gmt($cutoff, 'Y-m-d H:i:s');

        // Identity guard (5658f2): rows holding a visitor name/email are LEADS
        // — the Leads tab lists them and the legacy option rows they replace
        // were never expired. Retention only sweeps anonymous session state;
        // identity rows leave via the eraser / lead-delete / wipe paths.
        //
        // d0cae1: when something listens for ended sessions the swept ids must
        // be known, so the delete goes SELECT-then-DELETE-by-id. Unhooked, the
        // original single-statement bulk delete runs unchanged.
        if (has_action('mxchat_session_ended')) {
            $swept_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT session_id FROM `$table` WHERE updated_at IS NOT NULL AND updated_at < %s
                     AND (visitor_email IS NULL OR visitor_email = '')
                     AND (visitor_name IS NULL OR visitor_name = '')
                     LIMIT %d",
                    $cutoff,
                    $limit
                )
            );

            if (empty($swept_ids)) {
                return 0;
            }

            $placeholders = implode(',', array_fill(0, count($swept_ids), '%s'));
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM `$table` WHERE session_id IN ($placeholders)",
                    $swept_ids
                )
            );

            if ($deleted) {
                self::flush_cache();
            }

            foreach ($swept_ids as $swept_id) {
                do_action('mxchat_session_ended', $swept_id, array('reason' => 'retention'));
            }

            return (int) $deleted;
        }

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `$table` WHERE updated_at IS NOT NULL AND updated_at < %s
                 AND (visitor_email IS NULL OR visitor_email = '')
                 AND (visitor_name IS NULL OR visitor_name = '')
                 LIMIT %d",
                $cutoff,
                $limit
            )
        );

        if ($deleted) {
            self::flush_cache();
        }

        return (int) $deleted;
    }

    /** Daily cron: drain migration faster, then sweep with a larger cap. */
    public static function run_maintenance() {
        for ($i = 0; $i < 10; $i++) {
            if (self::maybe_migrate() === 0) {
                break;
            }
        }

        self::trim(self::TRIM_BATCH * 10);
    }

    /* ---------------------------------------------------------------------
     * Reporting — used by the admin tools + the verification harness.
     * ------------------------------------------------------------------ */

    public static function stats() {
        global $wpdb;

        $table = self::table();
        $legacy_likes = array();
        $params = array();
        foreach (self::$legacy_prefixes as $prefix) {
            $legacy_likes[] = 'option_name LIKE %s';
            $params[]       = $wpdb->esc_like($prefix) . '%';
        }

        $legacy_remaining = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE " . implode(' OR ', $legacy_likes),
                $params
            )
        );

        return array(
            'sessions'         => (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`"),
            'legacy_remaining' => $legacy_remaining,
            'migration'        => self::migration_state(),
            'retention_days'   => self::retention_days(),
        );
    }
}
