<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * MxChat_Cache_Purge — purges known full-page caches when MxChat settings save.
 *
 * Widget settings are printed inline into page HTML (wp_localize_script in
 * class-mxchat-integrator.php), so a full-page cache keeps serving the old
 * snapshot after an admin changes a setting — toggles look broken until the
 * cache is cleared by hand. This module clears the page caches we can reach
 * from PHP the moment mxchat_options is written, using each cache plugin's
 * own public purge API (guarded by existence checks, so absent plugins are
 * a no-op). Companion to the 3.2.6 page-cache compatibility filters in
 * mxchat-basic.php, which keep the chat AJAX endpoints out of those same
 * caches; this handles the cached page HTML itself.
 *
 * Host-level caches and CDNs (Cloudflare APO etc.) cannot be purged from
 * PHP — for those, the widget re-fetches its behavior settings when opened
 * (see MxChat_Integrator::get_dynamic_widget_settings()).
 *
 * Debounce: the admin screen autosaves per field, so a settings session can
 * write mxchat_options many times in a minute. We purge at most once per
 * DEBOUNCE_SECONDS; writes that land inside the window schedule one deferred
 * purge via wp-cron so the final state always gets flushed.
 *
 * @since 3.2.9 (plan-32db95)
 */
class MxChat_Cache_Purge {

    const DEBOUNCE_TRANSIENT = 'mxchat_cache_purge_debounce';
    const DEBOUNCE_SECONDS   = 30;
    const DEFERRED_EVENT     = 'mxchat_deferred_cache_purge';

    private static $instance = null;

    /**
     * Whether this request already queued a shutdown purge (one per request).
     *
     * @var bool
     */
    private $purge_queued = false;

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        foreach ($this->watched_options() as $option_name) {
            add_action('update_option_' . $option_name, array($this, 'queue_purge'), 10, 0);
            add_action('add_option_' . $option_name, array($this, 'queue_purge'), 10, 0);
        }
        add_action(self::DEFERRED_EVENT, array($this, 'purge_page_caches'));
    }

    /**
     * Option names whose writes trigger a page-cache purge.
     *
     * Defaults cover every option whose values reach the cached page's inline
     * widget payload: mxchat_options (settings/behavior gates),
     * mxchat_theme_options (active AI theme CSS + bot theme assignments —
     * written only by the MxChat Theme add-on's admin AJAX actions), and
     * mxchat_prompts_options (Pinecone toggle — written only by the prompts
     * autosave). All write sites are admin-action-driven; the debounce
     * absorbs back-to-back writes like apply-and-save.
     *
     * @return string[]
     */
    private function watched_options() {
        $defaults = array('mxchat_options', 'mxchat_theme_options', 'mxchat_prompts_options');
        $options  = apply_filters('mxchat_cache_purge_watched_options', $defaults);
        return is_array($options) ? array_filter(array_map('strval', $options)) : $defaults;
    }

    /**
     * Coalesce all option writes in this request into one shutdown purge.
     */
    public function queue_purge() {
        if ($this->purge_queued) {
            return;
        }
        $this->purge_queued = true;
        add_action('shutdown', array($this, 'run_queued_purge'), 5);
    }

    /**
     * Shutdown handler: purge unless one ran inside the debounce window; in
     * that case schedule a single deferred purge so the last write still wins.
     */
    public function run_queued_purge() {
        if (get_transient(self::DEBOUNCE_TRANSIENT)) {
            if (!wp_next_scheduled(self::DEFERRED_EVENT)) {
                wp_schedule_single_event(time() + self::DEBOUNCE_SECONDS, self::DEFERRED_EVENT);
            }
            return;
        }
        set_transient(self::DEBOUNCE_TRANSIENT, 1, self::DEBOUNCE_SECONDS);
        $this->purge_page_caches();
    }

    /**
     * Purge every supported page cache via its own public API. Each call is
     * guarded so installs without that plugin skip it silently.
     */
    public function purge_page_caches() {
        // WP Rocket — public helper, clears the whole domain's page cache.
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // LiteSpeed Cache — documented third-party purge API.
        if (defined('LSCWP_V') || class_exists('LiteSpeed\Core')) {
            do_action('litespeed_purge_all');
        }

        // W3 Total Cache — page-cache flush helper.
        if (function_exists('w3tc_pgcache_flush')) {
            w3tc_pgcache_flush();
        }

        // WP Super Cache — global cache clear.
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        // FlyingPress — documented programmatic purge (purge_pages clears the
        // HTML page cache without rebuilding optimized assets):
        // https://docs.flyingpress.com/en/articles/11406092-programmatically-purge-and-preload-cache
        if (class_exists('\FlyingPress\Purge') && method_exists('\FlyingPress\Purge', 'purge_pages')) {
            \FlyingPress\Purge::purge_pages();
        }

        // Extension point for CDNs / host caches / custom setups.
        do_action('mxchat_purge_page_caches');
    }
}
