<?php
/**
 * File: includes/class-mxchat-live-agent-schedule.php
 *
 * Availability schedules for live-agent handoff (plan-mxchat-20260716-8ccaa2,
 * split per-channel by plan-mxchat-20260718-99d7a4).
 *
 * Slack and Telegram each own an INDEPENDENT schedule, rendered under their own
 * Integrations tab. They are separate, self-contained integrations in the admin
 * nav; a site may staff them differently (e.g. Slack for the day team, Telegram
 * for an overseas evening crew), and a Telegram-only owner must never have to
 * open the Slack tab to find the hours control.
 *
 * Off-hours behaves as if that channel's handoff were never enabled: the
 * channel's handover tool is withheld from the model in
 * MxChat_Tool_Registry::enabled_tools() — each channel independently — so the
 * bot never dangles a human it cannot reach, and the handover callbacks
 * themselves re-check (defense in depth, for any path that invokes them
 * directly).
 *
 * Stored in standalone options, deliberately NOT inside mxchat_options: the
 * schedule is a nested structure, and mxchat_sanitize() REBUILDS mxchat_options
 * on every autosave of any other field, stripping keys it has no isset-handler
 * for (the 2c02ea bug class). Standalone options sidestep that entirely.
 *
 * MIGRATION: 3.2.14 pre-release builds briefly shipped ONE shared schedule in
 * the legacy option (mxchat_live_agent_schedule). On first read, if a channel's
 * own option is absent and the legacy one exists, BOTH channels are seeded from
 * it — nobody who configured hours loses them. The legacy option is left in
 * place as a fallback read (a later cleanup may remove it); it is never
 * consulted once the per-channel options exist.
 *
 * DEFAULT IS OFF. An install that never touches this is byte-identical to
 * before: enabled = false -> is_within_hours() always returns true, and
 * availability stays governed solely by the manual status toggles.
 *
 * Times are evaluated in the SITE's timezone (wp_timezone()), never UTC and
 * never the visitor's — the schedule describes when *staff* are at their desks.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Live_Agent_Schedule {

    /**
     * LEGACY shared-schedule option (pre-split 3.2.14 builds). Migration source
     * and fallback only — never written to by current code, never read once the
     * per-channel options exist. See the file docblock for why it isn't in
     * mxchat_options.
     */
    const OPTION = 'mxchat_live_agent_schedule';

    /** Per-channel standalone options (plan 99d7a4; webhook added by d88e22). */
    const OPTION_SLACK    = 'mxchat_live_agent_schedule_slack';
    const OPTION_TELEGRAM = 'mxchat_live_agent_schedule_telegram';
    const OPTION_WEBHOOK  = 'mxchat_live_agent_schedule_webhook';

    /* ------------------------------------------------------------------ *
     *  Channels
     * ------------------------------------------------------------------ */

    /** The channels that carry a schedule. Key = channel id used across the API. */
    public static function channels() {
        return array('slack', 'telegram', 'webhook');
    }

    /**
     * Resolve a channel id to its option name. Unknown input maps to Slack —
     * every real call site passes a literal, so this is a typo net, not a
     * routing feature.
     */
    private static function option_name($channel) {
        if ($channel === 'telegram') {
            return self::OPTION_TELEGRAM;
        }
        if ($channel === 'webhook') {
            return self::OPTION_WEBHOOK;
        }
        return self::OPTION_SLACK;
    }

    /**
     * One-time seed of the per-channel options from the legacy shared option.
     * Idempotent and cheap: writes only when a channel option is ABSENT and the
     * legacy option holds an array. Runs lazily on first read rather than on an
     * upgrade hook — upgrade hooks demonstrably don't fire on every install
     * (DISABLE_WP_CRON sites, plan bc08a6), a read always does.
     */
    private static function maybe_migrate() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $legacy = get_option(self::OPTION, null);
        if (!is_array($legacy)) {
            return;
        }
        $seed = self::normalize($legacy);
        // Webhook (d88e22) is deliberately NOT seeded: the legacy shared option
        // predates the webhook channel, so it never expressed intent about it.
        foreach (array(self::OPTION_SLACK, self::OPTION_TELEGRAM) as $opt) {
            if (!is_array(get_option($opt, null))) {
                update_option($opt, $seed);
            }
        }
        // Legacy option intentionally NOT deleted — fallback for a downgrade,
        // removable in a later cleanup release.
    }

    /* ------------------------------------------------------------------ *
     *  State
     * ------------------------------------------------------------------ */

    /**
     * Shape of a never-configured schedule: disabled, with a sane Mon-Fri 9-5
     * pre-fill so the editor opens on something meaningful rather than blank.
     * The pre-fill is inert while `enabled` is false.
     *
     * Days are keyed 1 (Monday) .. 7 (Sunday) — PHP's ISO-8601 'N', so the key
     * IS the value date('N') returns. No lookup table to drift.
     */
    public static function defaults() {
        $days = array();
        foreach (range(1, 7) as $n) {
            $days[$n] = array(
                'enabled' => ($n <= 5), // Mon-Fri pre-filled, weekend off
                'start'   => '09:00',
                'end'     => '17:00',
            );
        }
        return array(
            'enabled' => false,
            'days'    => $days,
        );
    }

    /** One channel's saved schedule, normalized. Always safe to index. */
    public static function get($channel) {
        self::maybe_migrate();
        $saved = get_option(self::option_name($channel), null);
        if (!is_array($saved)) {
            return self::defaults();
        }
        return self::normalize($saved);
    }

    /** Is the schedule feature switched on for this channel? */
    public static function is_enabled($channel) {
        $s = self::get($channel);
        return !empty($s['enabled']);
    }

    /* ------------------------------------------------------------------ *
     *  Evaluation
     * ------------------------------------------------------------------ */

    /**
     * Is this channel inside a configured on-hours window right now?
     *
     * Returns TRUE when the channel's schedule is disabled — "no schedule" must
     * never mean "never available", or switching the feature off would strand
     * every install that had it on.
     *
     * @param string   $channel 'slack' or 'telegram'.
     * @param int|null $now_ts  Unix timestamp to evaluate (defaults to now).
     *                          Injectable so the window logic is testable
     *                          without waiting for Tuesday.
     */
    public static function is_within_hours($channel, $now_ts = null) {
        $sched = self::get($channel);
        if (empty($sched['enabled'])) {
            return true;
        }

        $tz  = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $now = new DateTimeImmutable('@' . ($now_ts === null ? time() : (int) $now_ts));
        $now = $now->setTimezone($tz);

        $today     = (int) $now->format('N');            // 1 (Mon) .. 7 (Sun)
        $yesterday = ($today === 1) ? 7 : $today - 1;
        $minutes   = ((int) $now->format('G') * 60) + (int) $now->format('i');

        // Today's own window, plus any window that STARTED yesterday and wrapped
        // past midnight into today (an evening shift covers 00:30 the next day).
        if (self::day_covers($sched['days'][$today], $minutes, false)) {
            return true;
        }
        if (self::day_covers($sched['days'][$yesterday], $minutes, true)) {
            return true;
        }
        return false;
    }

    /**
     * Does one day's window cover $minutes past midnight?
     *
     * @param bool $from_yesterday Evaluating this day as YESTERDAY's entry, i.e.
     *                             asking only "did its window wrap into today".
     */
    private static function day_covers($day, $minutes, $from_yesterday) {
        if (empty($day) || empty($day['enabled'])) {
            return false;
        }
        $start = self::to_minutes($day['start']);
        $end   = self::to_minutes($day['end']);
        if ($start === null || $end === null) {
            return false;
        }

        if ($start === $end) {
            // Equal start/end = 24 hours (e.g. 00:00-00:00 on an always-staffed
            // day). It covers its own day fully and cannot wrap into the next.
            return !$from_yesterday;
        }

        if ($end > $start) {
            // Normal same-day window. Cannot spill into the following day.
            return !$from_yesterday && $minutes >= $start && $minutes < $end;
        }

        // Wraps past midnight (e.g. 22:00 -> 02:00): the tail belongs to the
        // NEXT calendar day, which is why yesterday's entry is consulted at all.
        return $from_yesterday ? ($minutes < $end) : ($minutes >= $start);
    }

    /** 'HH:MM' -> minutes past midnight, or null if unparseable. */
    private static function to_minutes($hhmm) {
        if (!is_string($hhmm) || !preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h > 23 || $i > 59) {
            return null;
        }
        return ($h * 60) + $i;
    }

    /* ------------------------------------------------------------------ *
     *  Save path
     * ------------------------------------------------------------------ */

    /** Coerce any input into the canonical shape. Unknown/garbage -> defaults. */
    public static function normalize($input) {
        $defaults = self::defaults();
        if (!is_array($input)) {
            return $defaults;
        }

        $out = array(
            'enabled' => !empty($input['enabled']),
            'days'    => array(),
        );

        $days = (isset($input['days']) && is_array($input['days'])) ? $input['days'] : array();
        foreach (range(1, 7) as $n) {
            $src   = isset($days[$n]) && is_array($days[$n]) ? $days[$n] : array();
            $start = isset($src['start']) ? self::clean_time($src['start']) : null;
            $end   = isset($src['end'])   ? self::clean_time($src['end'])   : null;
            $out['days'][$n] = array(
                'enabled' => !empty($src['enabled']),
                // An unparseable time falls back to the default rather than
                // disabling the day silently — a typo shouldn't drop coverage
                // without saying so.
                'start'   => $start !== null ? $start : $defaults['days'][$n]['start'],
                'end'     => $end   !== null ? $end   : $defaults['days'][$n]['end'],
            );
        }
        return $out;
    }

    /** Normalize a time string to zero-padded 'HH:MM', or null if invalid. */
    private static function clean_time($hhmm) {
        $mins = self::to_minutes($hhmm);
        if ($mins === null) {
            return null;
        }
        return sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
    }

    /** Persist one channel's schedule. Returns the normalized array written. */
    public static function save($channel, $input) {
        $clean = self::normalize($input);
        update_option(self::option_name($channel), $clean);
        return $clean;
    }

    /* ------------------------------------------------------------------ *
     *  Presentation helpers
     * ------------------------------------------------------------------ */

    /** Day labels, keyed to match defaults() (1 = Monday). Locale-aware. */
    public static function day_labels() {
        global $wp_locale;
        // $wp_locale->get_weekday() is 0-indexed on SUNDAY, ours is ISO (1=Mon).
        $map = array(1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 0);
        $out = array();
        foreach ($map as $iso => $wp_index) {
            $out[$iso] = ($wp_locale && method_exists($wp_locale, 'get_weekday'))
                ? $wp_locale->get_weekday($wp_index)
                : $iso;
        }
        return $out;
    }

    /**
     * Human label for the site timezone, so the admin never has to guess which
     * clock the schedule runs on (Maxwell's explicit ask on approval).
     */
    public static function timezone_label() {
        $tz_string = get_option('timezone_string');
        if (!empty($tz_string)) {
            return $tz_string;
        }
        $offset = (float) get_option('gmt_offset', 0);
        if ($offset == 0) {
            return 'UTC';
        }
        return 'UTC' . ($offset > 0 ? '+' : '') . rtrim(rtrim(number_format($offset, 2, '.', ''), '0'), '.');
    }
}
