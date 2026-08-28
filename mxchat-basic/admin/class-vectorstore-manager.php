<?php
/**
 * File: admin/class-vectorstore-manager.php
 *
 * OpenAI Vector Store write path: initial import + ongoing sync from the
 * knowledge base (plan 15b5c6). Until this class existed the plugin could only
 * READ from a Vector Store (Responses API file_search) — enabling Vector Store
 * mode froze the bot's knowledge at activation time because every KB write
 * still went to WordPress/Pinecone only.
 *
 * Design notes that matter:
 * - One KB entry = ONE file in the store. Content is handed to OpenAI whole
 *   (never pre-chunked here) — file_search chunks server-side, and one file
 *   per entry is what makes updates/deletes tractable.
 * - Vector Store files are NOT patchable in place. An update is
 *   upload-new -> attach-new -> detach+delete-old, and the KB-entry -> file_id
 *   mapping lives in {prefix}mxchat_vectorstore_files. Without that mapping
 *   the store accumulates stale duplicates and retrieval quality rots with no
 *   error anywhere. New file goes in BEFORE the old one is removed so a
 *   failure mid-swap leaves the entry answerable (a brief duplicate window is
 *   the safe failure, permanent absence is not); a failed old-file delete is
 *   parked as status=pending_delete and retried by the sweeper.
 * - Role-restricted entries are NEVER mirrored: file_search has no per-role
 *   filtering, so a restricted entry in the store would be served to every
 *   visitor. Import skips them, sync skips them, and a restriction change on
 *   a mirrored entry deletes its file.
 * - The initial import runs as self-rescheduling WP-Cron ticks (admin kickoff
 *   + progress UI), with a WP-CLI command (`wp mxchat vectorstore-import`)
 *   that drives the same worker synchronously. A 1,500-entry import will not
 *   finish in one request; cursor-based state in an option makes any
 *   interruption resumable without duplicates (the mapping table + content
 *   hash make re-processing an already-imported entry a no-op).
 * - Import uploads files individually, then attaches each tick's uploads with
 *   ONE /file_batches call per tick, per OpenAI's bulk-ingestion guidance.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class MxChat_Vectorstore_Manager {

    const TABLE = 'mxchat_vectorstore_files';
    const STATE_OPTION = 'mxchat_vectorstore_import_state';
    const TICK_HOOK = 'mxchat_vectorstore_import_tick';
    const SWEEP_HOOK = 'mxchat_vectorstore_sweep';
    const LOCK_TRANSIENT = 'mxchat_vectorstore_import_lock';

    /** Entries processed per import tick (also the /file_batches attach size). */
    const IMPORT_BATCH_SIZE = 20;
    /** Wall-clock budget per cron tick, seconds. */
    const TICK_TIME_BUDGET = 25;

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('wp_ajax_mxchat_vectorstore_import_start', array($this, 'ajax_import_start'));
        add_action('wp_ajax_mxchat_vectorstore_import_status', array($this, 'ajax_import_status'));
        add_action('wp_ajax_mxchat_vectorstore_import_resume', array($this, 'ajax_import_resume'));
        add_action('wp_ajax_mxchat_vectorstore_import_cancel', array($this, 'ajax_import_cancel'));
        add_action(self::TICK_HOOK, array($this, 'run_import_tick'));
        add_action(self::SWEEP_HOOK, array($this, 'run_sweep'));
        add_action('admin_init', array($this, 'maybe_schedule_sweep'));

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('mxchat vectorstore-import', array($this, 'cli_import'));
        }
    }

    // ========================================
    // CONFIG
    // ========================================

    /**
     * Resolve the sync configuration for a bot.
     *
     * Mirrors get_bot_vectorstore_config()'s resolution shape: default bot (or
     * no multi-bot add-on) reads the global option; other bots go through a
     * filter so the multi-bot add-on can supply a per-bot store — one store per
     * bot, same seam the Pinecone namespace work uses (793b82/d4c6bb approval
     * note). No filter implementation -> non-default bots do NOT fall back to
     * the default store: cross-bot bleed into one store is the bug we just
     * spent two Pinecone plans killing.
     *
     * @return array {enabled: bool, store_id: string, api_key: string}
     */
    public static function get_sync_config($bot_id = 'default') {
        $mxchat_options = get_option('mxchat_options', array());
        $api_key = $mxchat_options['api_key'] ?? '';

        if ($bot_id === 'default' || !class_exists('MxChat_Multi_Bot_Manager')) {
            $vs_options = get_option('mxchat_openai_vectorstore_options', array());
            $store_id = trim($vs_options['mxchat_vectorstore_sync_store_id'] ?? '');
            if ($store_id === '') {
                // Fall back to the first configured retrieval store — the
                // common single-store setup shouldn't need the ID twice.
                $ids = array_filter(array_map('trim', explode(',', $vs_options['mxchat_vectorstore_ids'] ?? '')));
                $store_id = $ids ? reset($ids) : '';
            }
            return array(
                'enabled' => ($vs_options['mxchat_vectorstore_sync_enabled'] ?? '0') === '1' && $store_id !== '' && $api_key !== '',
                'store_id' => $store_id,
                'api_key' => $api_key,
            );
        }

        $bot_config = apply_filters('mxchat_get_bot_vectorstore_sync_config', array(), $bot_id);
        $enabled = !empty($bot_config['enabled']) && !empty($bot_config['store_id']);
        return array(
            'enabled' => $enabled && $api_key !== '',
            'store_id' => $bot_config['store_id'] ?? '',
            'api_key' => $api_key,
        );
    }

    /**
     * Stable mapping key for a KB identity (https://, upload://, mxchat://).
     * Deliberately the same md5 the Pinecone path uses as its base vector id,
     * so a Pinecone vector_id doubles as the mapping key for URL-keyed entries.
     */
    public static function entry_key($source_url) {
        return md5((string) $source_url);
    }

    private static function has_stable_identity($source_url) {
        return !empty($source_url) && preg_match('#^(https?|upload|mxchat)://#i', $source_url);
    }

    // ========================================
    // SYNC ENTRY POINTS (called from MxChat_Utils)
    // ========================================

    /**
     * Mirror a KB write into the Vector Store. Failure here must never fail
     * the primary KB write — callers already stored successfully; we log and
     * return, and the entry self-heals on its next save or via re-import.
     */
    public static function sync_upsert_entry($source_url, $content, $bot_id = 'default', $content_type = 'content') {
        $config = self::get_sync_config($bot_id);
        if (!$config['enabled']) {
            return;
        }
        if (!self::has_stable_identity($source_url)) {
            // Brand-new manual content has no identity at this seam; it gains
            // one in storage and is picked up by the import / its next edit.
            return;
        }

        // Canonicalize through the stored KB rows when they exist so the save
        // path and the import path hash identical bytes — chunk reassembly
        // differs from pre-chunk content in whitespace, and a byte mismatch
        // here would re-upload every chunked entry on each import/save
        // ping-pong. Pinecone-mode entries have no local rows; the passed
        // content is used as-is there.
        $local = self::read_local_entry($source_url);
        if ($local !== null) {
            $content = $local['content'];
            $content_type = $local['content_type'];
            if ($local['restricted']) {
                self::log('skip restricted entry ' . $source_url);
                return;
            }
        }
        if (self::entry_is_restricted($source_url, $bot_id)) {
            // Never mirror role-restricted content — the store has no per-role
            // filtering, so it would be served to every visitor.
            self::log('skip restricted entry ' . $source_url);
            return;
        }

        $result = self::upsert_file($config, self::entry_key($source_url), $source_url, $content, $bot_id, $content_type);
        if (is_wp_error($result)) {
            self::log('sync upsert failed for ' . $source_url . ' — ' . $result->get_error_message());
        }
    }

    /**
     * Mirror a KB entry removal. Same failure isolation as sync_upsert_entry.
     */
    public static function sync_delete_entry($source_url, $bot_id = 'default') {
        if (!self::has_stable_identity($source_url)) {
            return;
        }
        self::sync_delete_by_key(self::entry_key($source_url), $bot_id);
    }

    /**
     * Removal by mapping key — for callers that hold a Pinecone vector id
     * rather than a URL (base id == md5(url) == our key; chunk ids reduce to
     * their base). Config-independent on purpose: even with sync toggled off,
     * deleting a KB entry should remove a previously-mirrored file rather than
     * strand it in the store.
     */
    public static function sync_delete_by_key($entry_key, $bot_id = 'default') {
        if (class_exists('MxChat_Chunker')) {
            $base = MxChat_Chunker::get_base_hash_from_vector_id($entry_key);
            if ($base !== null) {
                $entry_key = $base;
            }
        }
        if (!preg_match('/^[a-f0-9]{32}$/', (string) $entry_key)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, store_id, file_id FROM {$table} WHERE entry_key = %s AND bot_id = %s",
            $entry_key, $bot_id
        ));
        if (empty($rows)) {
            return;
        }

        $mxchat_options = get_option('mxchat_options', array());
        $api_key = $mxchat_options['api_key'] ?? '';

        foreach ($rows as $row) {
            // Park first, then attempt: if the API call dies mid-flight the
            // sweeper still knows this file is condemned.
            $wpdb->update($table, array('status' => 'pending_delete'), array('id' => $row->id), array('%s'), array('%d'));
            if ($api_key !== '' && self::remove_remote_file($api_key, $row->store_id, $row->file_id)) {
                $wpdb->delete($table, array('id' => $row->id), array('%d'));
            }
        }
    }

    /**
     * A role restriction just changed on a KB entry. Non-public -> pull the
     * mirrored file. Back to public -> best-effort re-mirror from the local
     * KB row (Pinecone-mode content isn't local; it re-mirrors on next save).
     */
    public static function handle_role_change($source_url, $bot_id, $new_restriction) {
        if (!self::has_stable_identity($source_url)) {
            return;
        }
        if (!empty($new_restriction) && $new_restriction !== 'public') {
            self::sync_delete_by_key(self::entry_key($source_url), $bot_id);
            return;
        }

        $config = self::get_sync_config($bot_id);
        if (!$config['enabled']) {
            return;
        }
        $entry = self::read_local_entry($source_url);
        if ($entry !== null) {
            self::sync_upsert_entry($source_url, $entry['content'], $bot_id, $entry['content_type']);
        }
    }

    // ========================================
    // CORE UPSERT / DELETE ENGINE
    // ========================================

    /**
     * The upload body for an entry — also the input to the change-detection
     * hash, so import and ongoing sync must build it identically.
     */
    private static function build_file_body($source_url, $content) {
        $body = '';
        if (preg_match('#^https?://#i', (string) $source_url)) {
            $body .= 'Source: ' . $source_url . "\n\n";
        }
        return $body . $content;
    }

    /**
     * Upload-new -> attach-new -> record -> detach+delete-old.
     *
     * @param bool $defer_attach Import path: skip the per-file attach and let
     *                           the caller batch-attach via /file_batches.
     * @return true|string|WP_Error true = swapped/attached, 'unchanged' = hash
     *         match no-op, string file_id when $defer_attach (caller attaches).
     */
    public static function upsert_file($config, $entry_key, $source_url, $content, $bot_id, $content_type, $defer_attach = false) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $body = self::build_file_body($source_url, $content);
        $hash = md5($body);

        $live = $wpdb->get_row($wpdb->prepare(
            "SELECT id, file_id, content_hash FROM {$table} WHERE store_id = %s AND bot_id = %s AND entry_key = %s AND status = 'live' LIMIT 1",
            $config['store_id'], $bot_id, $entry_key
        ));

        if ($live && $live->content_hash === $hash) {
            return 'unchanged';
        }

        $file_id = self::api_upload_file($config['api_key'], 'mxchat-kb-' . $entry_key . '.txt', $body);
        if (is_wp_error($file_id)) {
            if ($live) {
                $wpdb->update($table, array('last_error' => $file_id->get_error_message()), array('id' => $live->id), array('%s'), array('%d'));
            }
            return $file_id;
        }

        if (!$defer_attach) {
            $attached = self::api_attach_file($config['api_key'], $config['store_id'], $file_id);
            if (is_wp_error($attached)) {
                // Orphaned upload: not in the store (harmless to retrieval),
                // delete the file object so it doesn't leak storage.
                self::api_delete_file($config['api_key'], $file_id);
                if ($live) {
                    $wpdb->update($table, array('last_error' => $attached->get_error_message()), array('id' => $live->id), array('%s'), array('%d'));
                }
                return $attached;
            }
        }

        // Condemn any stranded pending_attach rows for this entry first — a
        // hard-killed earlier import may have uploaded a file that never made
        // it into a batch; its upload is reclaimed by the sweeper.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'pending_delete'
             WHERE store_id = %s AND bot_id = %s AND entry_key = %s AND status = 'pending_attach'",
            $config['store_id'], $bot_id, $entry_key
        ));

        // Record the new file. Deferred (import) uploads are NOT in the store
        // yet — they become 'live' only after the tick's file_batches call
        // succeeds; recording them as live immediately would make a kill
        // between upload and attach look like a completed import on resume
        // (hash match -> skipped -> file never attached).
        $wpdb->insert($table, array(
            'store_id' => $config['store_id'],
            'bot_id' => $bot_id,
            'entry_key' => $entry_key,
            'source_url' => $source_url,
            'file_id' => $file_id,
            'content_hash' => $hash,
            'status' => $defer_attach ? 'pending_attach' : 'live',
            'updated_at' => current_time('mysql'),
        ), array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));

        if ($live) {
            $wpdb->update($table, array('status' => 'pending_delete'), array('id' => $live->id), array('%s'), array('%d'));
            if (self::remove_remote_file($config['api_key'], $config['store_id'], $live->file_id)) {
                $wpdb->delete($table, array('id' => $live->id), array('%d'));
            }
            // Failure path: row stays pending_delete; the sweeper retries.
        }

        return $defer_attach ? $file_id : true;
    }

    /**
     * Detach from the store AND delete the file object. 404 on either leg
     * counts as success — the goal state is "gone".
     */
    private static function remove_remote_file($api_key, $store_id, $file_id) {
        $detached = self::api_detach_file($api_key, $store_id, $file_id);
        if (is_wp_error($detached)) {
            return false;
        }
        $deleted = self::api_delete_file($api_key, $file_id);
        return !is_wp_error($deleted);
    }

    /**
     * Sweeper: retry condemned files that survived their first delete attempt.
     * Bounded per run; scheduled only while sync is enabled.
     */
    public function run_sweep() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $mxchat_options = get_option('mxchat_options', array());
        $api_key = $mxchat_options['api_key'] ?? '';
        if ($api_key === '') {
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT id, store_id, file_id FROM {$table} WHERE status = 'pending_delete' ORDER BY id ASC LIMIT 25"
        );
        foreach ($rows as $row) {
            if (self::remove_remote_file($api_key, $row->store_id, $row->file_id)) {
                $wpdb->delete($table, array('id' => $row->id), array('%d'));
            }
        }
    }

    public function maybe_schedule_sweep() {
        $config = self::get_sync_config('default');
        $scheduled = wp_next_scheduled(self::SWEEP_HOOK);
        if ($config['enabled'] && !$scheduled) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', self::SWEEP_HOOK);
        } elseif (!$config['enabled'] && $scheduled) {
            // Leave scheduled while any condemned rows remain — disabling sync
            // shouldn't strand files the store was already told to forget.
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE;
            $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending_delete'");
            if ($pending === 0) {
                wp_unschedule_event($scheduled, self::SWEEP_HOOK);
            }
        }
    }

    // ========================================
    // ROLE / LOCAL-ROW HELPERS
    // ========================================

    /**
     * Is this entry role-restricted right now? WP mode: the KB row's column.
     * Pinecone mode: the roles table keyed by base vector id.
     */
    private static function entry_is_restricted($source_url, $bot_id) {
        global $wpdb;

        $kb_table = $wpdb->prefix . 'mxchat_system_prompt_content';
        $restriction = $wpdb->get_var($wpdb->prepare(
            "SELECT role_restriction FROM {$kb_table} WHERE source_url = %s LIMIT 1",
            $source_url
        ));
        if ($restriction !== null && $restriction !== '' && $restriction !== 'public') {
            return true;
        }

        $roles_table = $wpdb->prefix . 'mxchat_pinecone_roles';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $roles_table)) === $roles_table) {
            $restriction = $wpdb->get_var($wpdb->prepare(
                "SELECT role_restriction FROM {$roles_table} WHERE vector_id = %s LIMIT 1",
                self::entry_key($source_url)
            ));
            if ($restriction !== null && $restriction !== '' && $restriction !== 'public') {
                return true;
            }
        }
        return false;
    }

    /**
     * Reassemble one entry's full text from the local KB table (chunked rows
     * carry a JSON metadata prefix and are ordered by chunk_index).
     *
     * @return array|null {content, content_type, restricted} or null if absent.
     */
    private static function read_local_entry($source_url) {
        global $wpdb;
        $kb_table = $wpdb->prefix . 'mxchat_system_prompt_content';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT article_content, content_type, role_restriction FROM {$kb_table} WHERE source_url = %s ORDER BY id ASC",
            $source_url
        ));
        if (empty($rows)) {
            return null;
        }

        $restricted = false;
        $content_type = 'content';
        $parts = array();
        foreach ($rows as $row) {
            if (!empty($row->role_restriction) && $row->role_restriction !== 'public') {
                $restricted = true;
            }
            $content_type = $row->content_type ?: $content_type;
            $parsed = MxChat_Chunker::parse_stored_chunk($row->article_content);
            $index = isset($parsed['metadata']['chunk_index']) ? (int) $parsed['metadata']['chunk_index'] : count($parts);
            $parts[$index] = $parsed['text'];
        }
        ksort($parts);

        return array(
            'content' => implode("\n\n", $parts),
            'content_type' => $content_type,
            'restricted' => $restricted,
        );
    }

    // ========================================
    // INITIAL IMPORT (cron ticks + CLI)
    // ========================================

    private static function default_state() {
        return array(
            'status' => 'idle', // idle|running|done|cancelled|error
            'store_id' => '',
            'bot_id' => 'default',
            'cursor' => 0,       // MIN(id) of the last entry group processed
            'total' => 0,        // distinct entries at kickoff
            'processed' => 0,
            'imported' => 0,
            'unchanged' => 0,
            'skipped_restricted' => 0,
            'skipped_no_identity' => 0,
            'failed' => 0,
            'last_error' => '',
            'started_at' => 0,
            'updated_at' => 0,
        );
    }

    public static function get_import_state() {
        $state = get_option(self::STATE_OPTION, array());
        return array_merge(self::default_state(), is_array($state) ? $state : array());
    }

    private static function save_import_state($state) {
        $state['updated_at'] = time();
        update_option(self::STATE_OPTION, $state, false);
    }

    /**
     * Process up to $limit entry groups from the local KB table. Shared by the
     * cron tick and the CLI loop. Returns the updated state.
     */
    public function import_work($state, $limit, $deadline = null) {
        global $wpdb;
        $kb_table = $wpdb->prefix . 'mxchat_system_prompt_content';

        $config = self::get_sync_config($state['bot_id']);
        if (!$config['enabled'] || $config['store_id'] !== $state['store_id']) {
            $state['status'] = 'error';
            $state['last_error'] = __('Sync was disabled or the target store changed mid-import.', 'mxchat');
            return $state;
        }

        // Entry = distinct source_url; cursor over MIN(id) keeps the scan
        // stable while rows are inserted/deleted around it.
        $groups = $wpdb->get_results($wpdb->prepare(
            "SELECT source_url, MIN(id) AS mid FROM {$kb_table}
             GROUP BY source_url HAVING mid > %d ORDER BY mid ASC LIMIT %d",
            (int) $state['cursor'], $limit
        ));

        if (empty($groups)) {
            $state['status'] = 'done';
            return $state;
        }

        $batch_file_ids = array();

        foreach ($groups as $group) {
            if ($deadline !== null && microtime(true) > $deadline) {
                break; // budget spent — cursor already reflects finished work
            }

            $state['cursor'] = (int) $group->mid;
            $state['processed']++;

            $source_url = (string) $group->source_url;
            if (!self::has_stable_identity($source_url)) {
                $state['skipped_no_identity']++;
                continue;
            }

            $entry = self::read_local_entry($source_url);
            if ($entry === null) {
                continue; // deleted between the group scan and now
            }
            if ($entry['restricted']) {
                $state['skipped_restricted']++;
                continue;
            }

            $result = self::upsert_file(
                $config,
                self::entry_key($source_url),
                $source_url,
                $entry['content'],
                $state['bot_id'],
                $entry['content_type'],
                true // defer attach — batched below
            );

            if (is_wp_error($result)) {
                $state['failed']++;
                $state['last_error'] = $result->get_error_message();
            } elseif ($result === 'unchanged') {
                $state['unchanged']++;
            } else {
                $batch_file_ids[] = $result;
                $state['imported']++;
            }

            usleep(50000); // 0.05s between uploads, same pacing as Pinecone ops
        }

        // Attach everything this pass uploaded with one file_batches call,
        // then promote the mappings to live. Until that promotion, a killed
        // run's uploads read as pending_attach and are re-imported on resume.
        if (!empty($batch_file_ids)) {
            $table = $wpdb->prefix . self::TABLE;
            $batch = self::api_create_file_batch($config['api_key'], $config['store_id'], $batch_file_ids);
            if (is_wp_error($batch)) {
                // Files exist but aren't in the store: condemn the mappings so
                // the sweeper reclaims the uploads, and count the entries as
                // failed — a re-run re-imports them (no live hash rows remain).
                foreach ($batch_file_ids as $fid) {
                    $wpdb->update($table, array('status' => 'pending_delete'), array('file_id' => $fid, 'status' => 'pending_attach'), array('%s'), array('%s', '%s'));
                }
                $state['failed'] += count($batch_file_ids);
                $state['imported'] -= count($batch_file_ids);
                $state['last_error'] = 'file_batches: ' . $batch->get_error_message();
            } else {
                foreach ($batch_file_ids as $fid) {
                    $wpdb->update($table, array('status' => 'live'), array('file_id' => $fid, 'status' => 'pending_attach'), array('%s'), array('%s', '%s'));
                }
            }
        }

        return $state;
    }

    public function run_import_tick() {
        if (get_transient(self::LOCK_TRANSIENT)) {
            return; // another tick is mid-flight
        }
        set_transient(self::LOCK_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);

        $state = self::get_import_state();
        if ($state['status'] !== 'running') {
            delete_transient(self::LOCK_TRANSIENT);
            return;
        }

        $state = $this->import_work($state, self::IMPORT_BATCH_SIZE, microtime(true) + self::TICK_TIME_BUDGET);
        self::save_import_state($state);
        delete_transient(self::LOCK_TRANSIENT);

        if ($state['status'] === 'running') {
            wp_schedule_single_event(time() + 2, self::TICK_HOOK);
        }
    }

    // ========================================
    // AJAX (admin UI)
    // ========================================

    private function ajax_guard() {
        check_ajax_referer('mxchat_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'mxchat')));
            exit;
        }
    }

    public function ajax_import_start() {
        $this->ajax_guard();

        $config = self::get_sync_config('default');
        if (!$config['enabled']) {
            wp_send_json_error(array('message' => __('Enable sync and set a target Vector Store ID (and OpenAI API key) first, then save settings.', 'mxchat')));
        }

        $state = self::get_import_state();
        if ($state['status'] === 'running') {
            wp_send_json_error(array('message' => __('An import is already running.', 'mxchat')));
        }

        // Validate the store really exists before burning uploads on a typo.
        $store = self::api_get_store($config['api_key'], $config['store_id']);
        if (is_wp_error($store)) {
            wp_send_json_error(array('message' => sprintf(__('Vector Store check failed: %s', 'mxchat'), $store->get_error_message())));
        }

        global $wpdb;
        $kb_table = $wpdb->prefix . 'mxchat_system_prompt_content';
        $total = (int) $wpdb->get_var("SELECT COUNT(DISTINCT source_url) FROM {$kb_table}");

        $state = self::default_state();
        $state['status'] = 'running';
        $state['store_id'] = $config['store_id'];
        $state['total'] = $total;
        $state['started_at'] = time();
        self::save_import_state($state);

        wp_schedule_single_event(time() + 1, self::TICK_HOOK);
        spawn_cron();

        wp_send_json_success(array('state' => self::get_import_state()));
    }

    public function ajax_import_status() {
        $this->ajax_guard();
        $state = self::get_import_state();
        // A running import whose last heartbeat is stale has lost its cron
        // chain (server restart, cron blocked) — surface a Resume affordance
        // instead of a forever-spinner.
        $state['stalled'] = ($state['status'] === 'running' && (time() - (int) $state['updated_at']) > 120);
        wp_send_json_success(array('state' => $state, 'mapped' => self::mapped_file_count()));
    }

    public function ajax_import_resume() {
        $this->ajax_guard();
        $state = self::get_import_state();
        if ($state['status'] !== 'running') {
            wp_send_json_error(array('message' => __('No interrupted import to resume.', 'mxchat')));
        }
        if (!wp_next_scheduled(self::TICK_HOOK)) {
            wp_schedule_single_event(time() + 1, self::TICK_HOOK);
        }
        spawn_cron();
        wp_send_json_success(array('state' => self::get_import_state()));
    }

    public function ajax_import_cancel() {
        $this->ajax_guard();
        $state = self::get_import_state();
        if ($state['status'] === 'running') {
            $state['status'] = 'cancelled';
            self::save_import_state($state);
        }
        wp_send_json_success(array('state' => self::get_import_state()));
    }

    public static function mapped_file_count() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return 0;
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'live'");
    }

    // ========================================
    // WP-CLI
    // ========================================

    /**
     * Import the local knowledge base into the configured Vector Store.
     *
     * ## OPTIONS
     *
     * [--restart]
     * : Discard prior import progress and start from the beginning (already-
     *   mirrored, unchanged entries are still skipped via the mapping table).
     *
     * ## EXAMPLES
     *
     *     wp mxchat vectorstore-import
     */
    public function cli_import($args, $assoc_args) {
        $config = self::get_sync_config('default');
        if (!$config['enabled']) {
            WP_CLI::error('Vector Store sync is not enabled (needs the sync toggle, a store ID, and the OpenAI API key).');
        }

        $store = self::api_get_store($config['api_key'], $config['store_id']);
        if (is_wp_error($store)) {
            WP_CLI::error('Vector Store check failed: ' . $store->get_error_message());
        }

        $state = self::get_import_state();
        if (!empty($assoc_args['restart']) || $state['status'] !== 'running') {
            global $wpdb;
            $kb_table = $wpdb->prefix . 'mxchat_system_prompt_content';
            $state = self::default_state();
            $state['status'] = 'running';
            $state['store_id'] = $config['store_id'];
            $state['total'] = (int) $wpdb->get_var("SELECT COUNT(DISTINCT source_url) FROM {$kb_table}");
            $state['started_at'] = time();
            self::save_import_state($state);
        } else {
            WP_CLI::log(sprintf('Resuming interrupted import at %d/%d.', $state['processed'], $state['total']));
        }

        while ($state['status'] === 'running') {
            $state = $this->import_work($state, self::IMPORT_BATCH_SIZE);
            self::save_import_state($state);
            WP_CLI::log(sprintf(
                '%d/%d processed — %d uploaded, %d unchanged, %d restricted-skipped, %d no-identity, %d failed',
                $state['processed'], $state['total'], $state['imported'], $state['unchanged'],
                $state['skipped_restricted'], $state['skipped_no_identity'], $state['failed']
            ));
        }

        if ($state['status'] === 'done') {
            WP_CLI::success(sprintf(
                'Import complete: %d uploaded, %d unchanged, %d skipped (restricted), %d skipped (no identity), %d failed.',
                $state['imported'], $state['unchanged'], $state['skipped_restricted'], $state['skipped_no_identity'], $state['failed']
            ));
            if ($state['failed'] > 0) {
                WP_CLI::warning('Last error: ' . $state['last_error'] . ' — re-run the command to retry failed entries.');
            }
        } else {
            WP_CLI::error('Import ended with status "' . $state['status'] . '": ' . $state['last_error']);
        }
    }

    // ========================================
    // OPENAI API LAYER
    // ========================================

    private static function api_headers($api_key, $json = true) {
        $headers = array(
            'Authorization' => 'Bearer ' . $api_key,
            'OpenAI-Beta' => 'assistants=v2',
        );
        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }
        return $headers;
    }

    private static function api_error($context, $response) {
        if (is_wp_error($response)) {
            return new WP_Error('vectorstore_request', $context . ': ' . $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $detail = $body['error']['message'] ?? ('HTTP ' . $code);
        return new WP_Error('vectorstore_api', $context . ': ' . $detail, array('status' => $code));
    }

    public static function api_get_store($api_key, $store_id) {
        $response = wp_remote_get('https://api.openai.com/v1/vector_stores/' . rawurlencode($store_id), array(
            'headers' => self::api_headers($api_key),
            'timeout' => 30,
        ));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return self::api_error('vector store lookup', $response);
        }
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * POST /v1/files (purpose=assistants), multipart built by hand — WP has no
     * native multipart support in wp_remote_post.
     *
     * @return string|WP_Error file id
     */
    public static function api_upload_file($api_key, $filename, $content) {
        $boundary = 'mxchatvs' . wp_generate_password(16, false);

        $body = '--' . $boundary . "\r\n";
        $body .= "Content-Disposition: form-data; name=\"purpose\"\r\n\r\n";
        $body .= "assistants\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . "\"\r\n";
        $body .= "Content-Type: text/plain\r\n\r\n";
        $body .= $content . "\r\n";
        $body .= '--' . $boundary . "--\r\n";

        $response = wp_remote_post('https://api.openai.com/v1/files', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ),
            'body' => $body,
            'timeout' => 60,
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return self::api_error('file upload', $response);
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['id'])) {
            return new WP_Error('vectorstore_api', 'file upload: response carried no file id');
        }
        return $data['id'];
    }

    public static function api_attach_file($api_key, $store_id, $file_id) {
        $response = wp_remote_post('https://api.openai.com/v1/vector_stores/' . rawurlencode($store_id) . '/files', array(
            'headers' => self::api_headers($api_key),
            'body' => wp_json_encode(array('file_id' => $file_id)),
            'timeout' => 30,
        ));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return self::api_error('file attach', $response);
        }
        return true;
    }

    public static function api_create_file_batch($api_key, $store_id, $file_ids) {
        $response = wp_remote_post('https://api.openai.com/v1/vector_stores/' . rawurlencode($store_id) . '/file_batches', array(
            'headers' => self::api_headers($api_key),
            'body' => wp_json_encode(array('file_ids' => array_values($file_ids))),
            'timeout' => 60,
        ));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return self::api_error('file batch', $response);
        }
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    public static function api_detach_file($api_key, $store_id, $file_id) {
        $response = wp_remote_request('https://api.openai.com/v1/vector_stores/' . rawurlencode($store_id) . '/files/' . rawurlencode($file_id), array(
            'method' => 'DELETE',
            'headers' => self::api_headers($api_key),
            'timeout' => 30,
        ));
        $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        if (is_wp_error($response) || ($code !== 200 && $code !== 404)) {
            return self::api_error('file detach', $response);
        }
        return true;
    }

    public static function api_delete_file($api_key, $file_id) {
        $response = wp_remote_request('https://api.openai.com/v1/files/' . rawurlencode($file_id), array(
            'method' => 'DELETE',
            'headers' => self::api_headers($api_key),
            'timeout' => 30,
        ));
        $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        if (is_wp_error($response) || ($code !== 200 && $code !== 404)) {
            return self::api_error('file delete', $response);
        }
        return true;
    }

    private static function log($message) {
        if (class_exists('MxChat_Admin') && method_exists('MxChat_Admin', 'mxchat_log_debug')) {
            MxChat_Admin::mxchat_log_debug('vectorstore_sync', $message);
        }
    }
}
