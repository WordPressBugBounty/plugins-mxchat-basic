<?php
/**
 * MxChat Licence Check — asks mxchat.ai whether this site's domain is still
 * switched on for its licence key, and honours an explicit "off" from the
 * licence holder's My Account after a three-day grace period.
 *
 * Plan: plan-mxchat-20261007-c93ede (Support Ticket 467). An Agency licence
 * holder who resells to clients could see the domains activated on a key but
 * had no way to switch one off, and even a server-side unlink left the old
 * install running Pro: `mxchat_license_status` is a local option written at
 * activation and never re-checked.
 *
 * The rules, from Maxwell's approval note (they override the original spec):
 *
 *  1. Turning back on is immediate. A site that is off (or in its grace
 *     period) re-checks when an admin opens any MxChat screen, at most once a
 *     minute; on the "Check license now" button; and hourly in the background.
 *     Activating from the site's own Activate button stays instant as before.
 *  2. A site that is on checks once a day, at a random time per site.
 *  3. ONLY AN EXPLICIT "OFF" COUNTS. A timeout, a 5xx, a non-JSON body, a DNS
 *     failure or an mxchat.ai outage never switches a site off. Today's
 *     production endpoint does not know this route yet and answers
 *     {"success":false,"data":"Invalid action"} — that is an error here too,
 *     so a core shipped ahead of the website change keeps every site on.
 *  4. A site move is not "off". A valid key on a domain mxchat.ai does not
 *     recognise ("unknown") stays on.
 *  5. Grace period: after the first explicit off the admin sees a notice and
 *     Pro is switched off only when an off answer arrives three days after the
 *     first one with no on/unknown in between. Nothing else is touched:
 *     settings, knowledge, the saved key and email, and the free features all
 *     stay exactly as they are, so switching back on restores Pro in place.
 *
 * What is sent to mxchat.ai: the licence key, the account email, the site's
 * host name and the plugin version (the same three identifiers the existing
 * activate / deactivate / check-domain calls already send, plus the version).
 * The readme's "External services" section documents all four calls.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_License_Check {

    const ENDPOINT        = 'https://mxchat.ai/mxchat-api/license-status';
    const OPTION          = 'mxchat_license_check';
    const CRON_HOOK       = 'mxchat_license_check';
    const NONCE_CHECK     = 'mxchat_license_check_now';
    const NONCE_DISMISS   = 'mxchat_license_notice_dismiss';

    const GRACE_SECONDS   = 3 * DAY_IN_SECONDS;
    const DAILY_SECONDS   = DAY_IN_SECONDS;
    const HOURLY_SECONDS  = HOUR_IN_SECONDS;
    const RETRY_SECONDS   = 6 * HOUR_IN_SECONDS;   // after an unanswered check on a site that is on
    const ADMIN_SECONDS   = MINUTE_IN_SECONDS;     // admin-screen re-check while off / in grace
    const BUTTON_SECONDS  = 10;                    // "Check license now" rate limit
    const DISMISS_SECONDS = DAY_IN_SECONDS;        // a dismissed notice comes back after a day
    const TIMEOUT_CRON    = 15;
    const TIMEOUT_ADMIN   = 8;

    /**
     * The refusals mxchat.ai's activation gate answers with, in the WooCommerce
     * Software add-on's own error shape (plan c93ede rework): each one is the
     * licence owner's (or an administrator's) explicit decision, never a slot
     * count or a transport problem.
     */
    public static function owner_reasons() {
        return array(
            '106' => 'switched_off',
            '107' => 'new_sites_blocked',
            '108' => 'revoked',
        );
    }

    public static function is_owner_reason($reason) {
        return in_array((string) $reason, array('switched_off', 'new_sites_blocked', 'revoked'), true);
    }

    public static function init() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'cron_check'));

        if (is_admin()) {
            add_action('admin_init', array(__CLASS__, 'admin_screen_check'), 5);
            add_action('admin_notices', array(__CLASS__, 'render_notice'));
            add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
            add_action('wp_ajax_mxchat_license_check_now', array(__CLASS__, 'ajax_check_now'));
            add_action('wp_ajax_mxchat_license_notice_dismiss', array(__CLASS__, 'ajax_dismiss_notice'));
        }

        self::ensure_scheduled();
    }

    /* ---------------------------------------------------------------------
     * State
     * ------------------------------------------------------------------ */

    public static function defaults() {
        return array(
            'last_check'   => 0,   // unix time of the last answer of any kind
            'last_result'  => '',  // on | off | unknown | error | ''
            'last_reason'  => '',  // the server's reason, or the transport / http / malformed code
            'last_source'  => '',  // cron | admin | button
            'off_since'    => 0,   // first explicit off of the current streak (0 = not off)
            'off_count'    => 0,
            'revoked_at'   => 0,   // when this class set mxchat_license_status to inactive (0 = it did not)
            'restored_at'  => 0,   // set when Pro was switched back on by a check; cleared once the notice has shown
            'next_check'   => 0,   // unix time the next background check is due
            'dismissed_at' => 0,   // the grace / off notice was dismissed at this time
            'refusal'      => '',  // the sentence mxchat.ai answered the last refused Activate click with
        );
    }

    public static function state() {
        $saved = get_option(self::OPTION, array());
        return is_array($saved) ? array_merge(self::defaults(), $saved) : self::defaults();
    }

    private static function save_state(array $state) {
        update_option(self::OPTION, $state, false);
    }

    /** The saved key, email and this site's host name; null when the site has no activation to check. */
    public static function credentials() {
        $key   = trim((string) get_option('mxchat_activation_key', ''));
        $email = trim((string) get_option('mxchat_pro_email', ''));
        if ($key === '' || $email === '') {
            return null;
        }
        return array('key' => $key, 'email' => $email, 'domain' => self::domain());
    }

    /** Exactly what activation sent as the domain, so the server-side match is the same string. */
    public static function domain() {
        $host = parse_url(home_url(), PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    public static function is_revoked() {
        $state = self::state();
        return !empty($state['revoked_at']);
    }

    public static function in_grace() {
        $state = self::state();
        return !empty($state['off_since']) && empty($state['revoked_at']);
    }

    /**
     * Clear the state. Called when the site activates (fresh start, first daily
     * check at a random time) or deactivates its licence (nothing left to check).
     */
    public static function reset($reschedule) {
        delete_option(self::OPTION);
        wp_clear_scheduled_hook(self::CRON_HOOK);
        if ($reschedule) {
            self::ensure_scheduled();
        }
    }

    /* ---------------------------------------------------------------------
     * Scheduling
     * ------------------------------------------------------------------ */

    /**
     * Make sure a background check is on the calendar. The first one lands at
     * a random moment within the next day and every later one is set from the
     * previous answer, so 850 sites spread their requests across the day.
     */
    public static function ensure_scheduled() {
        if (!self::credentials()) {
            return;
        }
        $state = self::state();
        $now   = time();
        if (empty($state['next_check'])) {
            $state['next_check'] = $now + wp_rand(MINUTE_IN_SECONDS, DAY_IN_SECONDS);
            self::save_state($state);
        }
        if (false === wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(max($now + MINUTE_IN_SECONDS, (int) $state['next_check']), self::CRON_HOOK);
        }
    }

    private static function reschedule($timestamp) {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_single_event(max(time() + MINUTE_IN_SECONDS, (int) $timestamp), self::CRON_HOOK);
    }

    /**
     * Is a check due? $context is 'cron' (background) or 'admin' (an admin
     * opened a MxChat screen). While the domain is off or in its grace period
     * the gap is an hour in the background and a minute on an admin screen;
     * otherwise the daily timetable applies.
     */
    public static function is_due($now, $context = 'cron') {
        if (!self::credentials()) {
            return false;
        }
        $state  = self::state();
        $status = get_option('mxchat_license_status', 'inactive');
        if ($status !== 'active' && empty($state['revoked_at'])) {
            return false; // never activated, or deactivated by hand: nothing to check
        }
        if (!empty($state['off_since']) || !empty($state['revoked_at'])) {
            $gap = ($context === 'admin') ? self::ADMIN_SECONDS : self::HOURLY_SECONDS;
            return ($now - (int) $state['last_check']) >= $gap;
        }
        return !empty($state['next_check']) && $now >= (int) $state['next_check'];
    }

    public static function cron_check() {
        if (self::is_due(time(), 'cron')) {
            self::run_check('cron');
            return;
        }
        $state = self::state();
        if (!empty($state['next_check'])) {
            self::reschedule((int) $state['next_check']);
        }
    }

    public static function admin_screen_check() {
        if (wp_doing_ajax() || wp_doing_cron() || !current_user_can('manage_options')) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === '' || strpos($page, 'mxchat') !== 0) {
            return;
        }
        if (self::is_due(time(), 'admin')) {
            self::run_check('admin');
        }
    }

    /* ---------------------------------------------------------------------
     * The check
     * ------------------------------------------------------------------ */

    /**
     * One request to mxchat.ai and the state change it implies. Returns the
     * interpreted answer plus the resulting Pro status, or null when the site
     * has nothing to check.
     */
    public static function run_check($source = 'cron') {
        $cred = self::credentials();
        if (!$cred) {
            return null;
        }
        $response = wp_remote_post(self::ENDPOINT, array(
            'timeout'   => ($source === 'cron') ? self::TIMEOUT_CRON : self::TIMEOUT_ADMIN,
            'sslverify' => true,
            'body'      => array(
                'license_key'    => $cred['key'],
                'email'          => $cred['email'],
                'domain'         => $cred['domain'],
                'plugin_version' => defined('MXCHAT_BASE_VERSION') ? MXCHAT_BASE_VERSION : '',
            ),
        ));
        return self::apply(self::interpret($response), $source, time());
    }

    /**
     * Turn an HTTP response into one of four words. Anything that is not a
     * well-formed answer is 'error' and changes nothing (rule 3).
     *
     * Expected body: {"success":true,"data":{"state":"on|off|unknown","reason":"…"}}
     */
    public static function interpret($response) {
        if (is_wp_error($response)) {
            return array('state' => 'error', 'reason' => 'transport_' . sanitize_key($response->get_error_code()));
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return array('state' => 'error', 'reason' => 'http_' . $code);
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['success']) || !isset($body['data']) || !is_array($body['data']) || !isset($body['data']['state'])) {
            return array('state' => 'error', 'reason' => 'malformed');
        }
        $state  = strtolower(trim((string) $body['data']['state']));
        $reason = isset($body['data']['reason']) ? sanitize_key((string) $body['data']['reason']) : '';
        if ($state === 'on' || $state === 'off') {
            return array('state' => $state, 'reason' => $reason);
        }
        return array('state' => 'unknown', 'reason' => ($reason !== '') ? $reason : 'state_' . sanitize_key($state));
    }

    /**
     * Apply an interpreted answer at time $now. Public so the verification rig
     * can drive the state machine without a network; the plugin only calls it
     * from run_check().
     */
    public static function apply(array $result, $source, $now) {
        $state  = self::state();
        $status = get_option('mxchat_license_status', 'inactive');

        $state['last_check']  = (int) $now;
        $state['last_result'] = $result['state'];
        $state['last_reason'] = isset($result['reason']) ? (string) $result['reason'] : '';
        $state['last_source'] = (string) $source;

        switch ($result['state']) {
            case 'off':
                if (empty($state['off_since'])) {
                    $state['off_since']    = (int) $now;
                    $state['off_count']    = 1;
                    $state['dismissed_at'] = 0; // a new streak brings the notice back
                } else {
                    $state['off_count'] = (int) $state['off_count'] + 1;
                }
                if (empty($state['revoked_at'])
                    && ($now - (int) $state['off_since']) >= self::GRACE_SECONDS
                    && $status === 'active') {
                    // Three days of off and still off: pause Pro. Only this one option moves.
                    update_option('mxchat_license_status', 'inactive');
                    $state['revoked_at']   = (int) $now;
                    $state['dismissed_at'] = 0;
                }
                $state['next_check'] = $now + self::HOURLY_SECONDS;
                break;

            case 'on':
            case 'unknown':
                if (!empty($state['revoked_at'])) {
                    update_option('mxchat_license_status', 'active');
                    $state['restored_at'] = (int) $now;
                }
                $state['off_since']    = 0;
                $state['off_count']    = 0;
                $state['revoked_at']   = 0;
                $state['dismissed_at'] = 0;
                $state['next_check']   = $now + self::DAILY_SECONDS - 1800 + wp_rand(0, 3600);
                break;

            default: // error: fail open, keep whatever streak there is
                $state['next_check'] = (!empty($state['off_since']) || !empty($state['revoked_at']))
                    ? $now + self::HOURLY_SECONDS
                    : $now + self::RETRY_SECONDS;
                break;
        }

        self::save_state($state);
        self::reschedule((int) $state['next_check']);

        $result['pro'] = get_option('mxchat_license_status', 'inactive');
        return $result;
    }

    /**
     * The Activate button was refused by mxchat.ai's gate (plan c93ede rework):
     * the licence owner switched this domain off, blocked new sites on the key,
     * or the key was revoked. The activation handler has already set Pro
     * inactive. When Pro was on until this click, treat the refusal as the
     * owner's explicit off (so the hourly check keeps running and the owner's
     * switch-on restores Pro); when the site was never on, just remember the
     * sentence for the Pro page. The add-on's own errors (invalid key, no
     * slots, incomplete order) are not the owner's doing and change nothing here.
     */
    public static function note_activation_refusal($code, $message, $was_active) {
        $reasons = self::owner_reasons();
        $code    = (string) $code;
        if (!isset($reasons[$code])) {
            return null;
        }
        $now   = time();
        $state = self::state();
        $state['last_check']  = $now;
        $state['last_result'] = 'off';
        $state['last_reason'] = $reasons[$code];
        $state['last_source'] = 'activate';
        $state['refusal']     = sanitize_text_field((string) $message);
        if ($was_active) {
            if (empty($state['off_since'])) {
                $state['off_since'] = $now;
                $state['off_count'] = 1;
            } else {
                $state['off_count'] = (int) $state['off_count'] + 1;
            }
            $state['revoked_at']   = $now;
            $state['dismissed_at'] = 0;
            $state['next_check']   = $now + self::HOURLY_SECONDS;
            self::save_state($state);
            self::reschedule((int) $state['next_check']);
            return $reasons[$code];
        }
        self::save_state($state);
        return $reasons[$code];
    }

    /**
     * The owner's refusal to show under the activation form while Pro is off and
     * no check is running (a site that was never on, or was deactivated by hand).
     */
    public static function last_refusal() {
        $state = self::state();
        if ($state['last_result'] !== 'off' || !self::is_owner_reason($state['last_reason']) || $state['refusal'] === '') {
            return '';
        }
        return (string) $state['refusal'];
    }

    /* ---------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------ */

    public static function ajax_check_now() {
        if (!current_user_can('manage_options') || !check_ajax_referer(self::NONCE_CHECK, 'nonce', false)) {
            wp_send_json_error(array('message' => esc_html__('Unauthorized', 'mxchat')), 403);
        }
        if (!self::credentials()) {
            wp_send_json_error(array('message' => esc_html__('No licence key is saved on this site.', 'mxchat')));
        }
        $before    = get_option('mxchat_license_status', 'inactive');
        $state     = self::state();
        $throttled = false;
        if ($state['last_result'] !== '' && (time() - (int) $state['last_check']) < self::BUTTON_SECONDS) {
            $throttled = true;
            $result    = array('state' => $state['last_result'], 'reason' => $state['last_reason']);
        } else {
            $result = self::run_check('button');
        }
        $after = get_option('mxchat_license_status', 'inactive');
        $view  = self::view_model();
        wp_send_json_success(array(
            'state'     => $result['state'],
            'reason'    => $result['reason'],
            'pro'       => $after,
            'reload'    => ($before !== $after),
            'throttled' => $throttled,
            'pill'      => $view['pill_text'],
            'pillClass' => $view['pill_class'],
            'message'   => $view['sentence'],
        ));
    }

    public static function ajax_dismiss_notice() {
        if (!current_user_can('manage_options') || !check_ajax_referer(self::NONCE_DISMISS, 'nonce', false)) {
            wp_send_json_error();
        }
        $state = self::state();
        $state['dismissed_at'] = time();
        self::save_state($state);
        wp_send_json_success();
    }

    /* ---------------------------------------------------------------------
     * Presentation
     * ------------------------------------------------------------------ */

    /** The pill and the sentence the Pro page and the AJAX reply both show. */
    public static function view_model() {
        $state  = self::state();
        $status = get_option('mxchat_license_status', 'inactive');
        $now    = time();

        if (!empty($state['revoked_at'])) {
            $since = wp_date(get_option('date_format'), (int) $state['revoked_at']);
            if ($state['last_reason'] === 'revoked') {
                $sentence = sprintf(
                    /* translators: %s: a date */
                    __('Managed by the licence owner. This licence key has been revoked, and Pro features have been paused since %s; your settings and knowledge are untouched. Contact MxChat support if you believe this is a mistake.', 'mxchat'),
                    $since
                );
            } elseif ($state['last_reason'] === 'new_sites_blocked') {
                $sentence = sprintf(
                    /* translators: 1: this site's domain, 2: a date */
                    __('Managed by the licence owner. New sites are blocked on this licence, so %1$s could not activate; Pro features have been paused since %2$s. Ask the licence holder to allow new sites in their mxchat.ai account, then click Check license now.', 'mxchat'),
                    self::domain(),
                    $since
                );
            } else {
                $sentence = sprintf(
                    /* translators: 1: this site's domain, 2: a date */
                    __('Managed by the licence owner. %1$s was switched off in the licence holder\'s MxChat account, and only they can switch it back on; this site cannot. Pro features have been paused since %2$s; your settings and knowledge are untouched. Once the domain is switched back on at mxchat.ai they return within the hour, or now with Check license now.', 'mxchat'),
                    self::domain(),
                    $since
                );
            }
            return array(
                'pill_class' => 'mxch-status-pill-disabled',
                'pill_text'  => ($state['last_reason'] === 'revoked') ? __('Revoked', 'mxchat') : __('Switched off', 'mxchat'),
                'sentence'   => $sentence,
            );
        }

        if (!empty($state['off_since'])) {
            return array(
                'pill_class' => 'mxch-status-pill-warning',
                'pill_text'  => __('Switching off', 'mxchat'),
                'sentence'   => sprintf(
                    /* translators: 1: this site's domain, 2: a date, 3: a date */
                    __('Managed by the licence owner. %1$s was switched off in the licence holder\'s MxChat account on %2$s, and only they can switch it back on. Pro features stay on until %3$s; if the domain is switched back on before then, nothing changes.', 'mxchat'),
                    self::domain(),
                    wp_date(get_option('date_format'), (int) $state['off_since']),
                    wp_date(get_option('date_format'), (int) $state['off_since'] + self::GRACE_SECONDS)
                ),
            );
        }

        if (empty($state['last_check'])) {
            return array(
                'pill_class' => ($status === 'active') ? 'mxch-status-pill-active' : 'mxch-status-pill-disabled',
                'pill_text'  => __('Not checked yet', 'mxchat'),
                'sentence'   => __('The first check with mxchat.ai runs within a day.', 'mxchat'),
            );
        }

        $ago = human_time_diff((int) $state['last_check'], $now);
        if ($state['last_result'] === 'error') {
            return array(
                'pill_class' => ($status === 'active') ? 'mxch-status-pill-active' : 'mxch-status-pill-disabled',
                'pill_text'  => ($status === 'active') ? __('Active', 'mxchat') : __('Inactive', 'mxchat'),
                'sentence'   => sprintf(
                    /* translators: 1: how long ago, 2: the error code */
                    __('mxchat.ai could not be reached %1$s ago (%2$s). Nothing changed; the check is retried later.', 'mxchat'),
                    $ago,
                    $state['last_reason']
                ),
            );
        }
        if ($state['last_result'] === 'unknown') {
            return array(
                'pill_class' => 'mxch-status-pill-active',
                'pill_text'  => __('Active', 'mxchat'),
                'sentence'   => sprintf(
                    /* translators: %s: how long ago */
                    __('Checked %s ago. mxchat.ai does not list this domain for the licence, which is normal after a site move; Pro stays on.', 'mxchat'),
                    $ago
                ),
            );
        }
        return array(
            'pill_class' => 'mxch-status-pill-active',
            'pill_text'  => __('Active', 'mxchat'),
            'sentence'   => sprintf(
                /* translators: %s: how long ago */
                __('Confirmed on by mxchat.ai %s ago. Checked once a day.', 'mxchat'),
                $ago
            ),
        );
    }

    /** True when the grace / off / restored notice has something to say and has not been dismissed today. */
    public static function notice_visible() {
        $state = self::state();
        if (!empty($state['restored_at'])) {
            return true;
        }
        if (empty($state['off_since']) && empty($state['revoked_at'])) {
            return false;
        }
        return empty($state['dismissed_at']) || (time() - (int) $state['dismissed_at']) >= self::DISMISS_SECONDS;
    }

    /** Pro & Extensions always; any other admin screen only while there is a notice to act on. */
    public static function enqueue_assets($hook_suffix = '') {
        if (!current_user_can('manage_options')) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'mxchat-activation' && !self::notice_visible()) {
            return;
        }
        $plugin_url = plugin_dir_url(dirname(__FILE__));
        $version    = defined('MXCHAT_VERSION') ? MXCHAT_VERSION : '1.0';
        wp_enqueue_script('mxchat-license-check-js', $plugin_url . 'js/license-check.js', array('jquery'), $version, true);
        wp_localize_script('mxchat-license-check-js', 'MxChatLicenseCheck', array(
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce(self::NONCE_CHECK),
            'dismissNonce' => wp_create_nonce(self::NONCE_DISMISS),
            'checking'     => __('Checking with mxchat.ai…', 'mxchat'),
            'failed'       => __('The check could not run. Try again in a moment.', 'mxchat'),
            'reloading'    => __('Pro status changed. Reloading…', 'mxchat'),
        ));
    }

    /**
     * The WordPress admin notice, on every admin screen except Pro & Extensions
     * (which renders the same thing inside its own card). Three shapes: grace
     * period, switched off, and a one-time "back on".
     */
    public static function render_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === 'mxchat-activation') {
            return;
        }
        $state = self::state();

        if (!empty($state['restored_at'])) {
            $state['restored_at'] = 0;
            self::save_state($state);
            ?>
            <div class="notice notice-success is-dismissible mxchat-license-check-notice">
                <p><strong><?php esc_html_e('MxChat Pro is back on.', 'mxchat'); ?></strong>
                <?php echo esc_html(sprintf(
                    /* translators: %s: this site's domain */
                    __('%s was switched back on in the licence holder\'s MxChat account, so Pro features are available again.', 'mxchat'),
                    self::domain()
                )); ?></p>
            </div>
            <?php
            return;
        }

        if (!self::notice_visible()) {
            return;
        }
        $view     = self::view_model();
        $revoked  = !empty($state['revoked_at']);
        $pro_url  = admin_url('admin.php?page=mxchat-activation');
        ?>
        <div class="notice <?php echo $revoked ? 'notice-error' : 'notice-warning'; ?> is-dismissible mxchat-license-check-notice" data-mxchat-license-notice="1">
            <p><strong><?php echo $revoked
                ? esc_html__('MxChat Pro is paused on this site.', 'mxchat')
                : esc_html__('MxChat Pro: this site has been switched off in the licence holder\'s account.', 'mxchat'); ?></strong></p>
            <p><?php echo esc_html($view['sentence']); ?></p>
            <p>
                <button type="button" class="button mxchat-license-check-now"><?php esc_html_e('Check license now', 'mxchat'); ?></button>
                <a class="button button-link" href="<?php echo esc_url($pro_url); ?>"><?php esc_html_e('Open Pro & Extensions', 'mxchat'); ?></a>
                <a class="button button-link" href="https://mxchat.ai/my-account/licenses/" target="_blank" rel="noopener"><?php esc_html_e('Open your licenses at mxchat.ai', 'mxchat'); ?></a>
                <span class="mxchat-license-check-msg" role="status"></span>
            </p>
        </div>
        <?php
    }
}
