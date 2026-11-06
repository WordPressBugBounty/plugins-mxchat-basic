<?php
/**
 * MxChat app: instant alerts (push notifications) to paired phones.
 *
 * A WordPress site cannot hold Firebase Cloud Messaging credentials, so it tells a small relay on Firebase
 * (appPushSend on mxchat-78eb1) which event happened and the relay does the push. Each phone registers with
 * the relay itself and gives the site only an opaque push key (mxp_...), kept on its device record with the
 * events it wants (MxChat_App_Auth::set_push()). A request to the relay carries the push keys, the event and
 * the chat session id, nothing else: no chat text, no visitor name, email or address. The phone then reads
 * the chat from the site with its own token before it shows the notification.
 *
 * Events: new_chat (a session's first visitor message is saved), message (a later visitor message, at most
 * one push per chat per minute), handoff (a visitor asks for a person: a live agent handoff runs), lead (a
 * visitor leaves an email), test (POST /app/devices/push/test). The app's own test chat (POST /app/chat,
 * sessions mxchat_app_*) never notifies, nor do bot or agent rows. Free and Pro alike. Filters:
 * mxchat_app_push_enabled (default true) turns it all off, mxchat_app_push_url points at another relay.
 *
 * The visitor's reply never waits for this. Events are queued during the request and sent on shutdown,
 * after fastcgi_finish_request() / litespeed_finish_request() has closed the connection; the relay's answer
 * is then read (3 s at most) and a key it no longer knows is cleared from its phone. On a server without
 * either function the request goes out without waiting for the answer (1 s at most). Nothing is written to
 * the output on shutdown, so a streamed reply that has already been sent is left as it is.
 *
 * @package MxChat
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_App_Push {

    const RELAY_URL          = 'https://us-central1-mxchat-78eb1.cloudfunctions.net/appPushSend';
    const KEYS_PER_REQUEST   = 20;
    const MESSAGE_THROTTLE   = 60;
    const THROTTLE_PREFIX    = 'mxchat_app_push_msg_';
    const APP_SESSION_PREFIX = 'mxchat_app_';

    /** Event sent to the relay => the phone's setting that opts in to it (MxChat_App_Auth::PUSH_DEFAULTS). */
    const EVENT_SETTING = array('new_chat' => 'new_chat', 'message' => 'messages', 'handoff' => 'handoff', 'lead' => 'lead');

    /** What the relay answers per key. */
    const RESULTS = array('sent', 'gone', 'unknown', 'rate_limited', 'error');

    /** event|session => ['event', 'session_id'], sent on shutdown. */
    private static $queue = array();

    public static function enabled() {
        return (bool) apply_filters('mxchat_app_push_enabled', true);
    }

    public static function relay_url() {
        return (string) apply_filters('mxchat_app_push_url', self::RELAY_URL);
    }

    // ------------------------------------------------------------------
    // Events (hooked at the bottom of this file)
    // ------------------------------------------------------------------

    /** mxchat_app_message_saved: a transcripts row was written (its id, never its text). */
    public static function on_message_saved($session_id, $role, $message_id) {
        if ($role !== 'user' || (int) $message_id <= 0 || !self::watches($session_id)) {
            return;
        }
        $new_chat = self::recipients('new_chat');
        $messages = self::recipients('message');
        if (!$new_chat && !$messages) {
            return;
        }
        if (self::first_visitor_message($session_id, (int) $message_id)) {
            if ($new_chat) {
                self::queue('new_chat', $session_id);
            }
            return;
        }
        if (!$messages) {
            return;
        }
        $throttle = self::THROTTLE_PREFIX . md5((string) $session_id);
        if (get_transient($throttle)) {
            return;
        }
        set_transient($throttle, 1, self::MESSAGE_THROTTLE);
        self::queue('message', $session_id);
    }

    /** mxchat_app_handoff_requested: a live agent handoff (Slack, Telegram or webhook) was asked for. */
    public static function on_handoff($session_id, $channel = '') {
        if (self::watches($session_id) && self::recipients('handoff')) {
            self::queue('handoff', $session_id);
        }
    }

    /** mxchat_app_lead_captured: a visitor left an email. */
    public static function on_lead($session_id) {
        if (self::watches($session_id) && self::recipients('lead')) {
            self::queue('lead', $session_id);
        }
    }

    /** Website chats only: not the app's own test chat, and not when alerts are switched off. */
    private static function watches($session_id) {
        $session_id = (string) $session_id;
        return $session_id !== '' && strpos($session_id, self::APP_SESSION_PREFIX) !== 0 && self::enabled();
    }

    /** No visitor row in this session before this one. */
    private static function first_visitor_message($session_id, $message_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'mxchat_chat_transcripts';
        return !$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE session_id = %s AND role = 'user' AND id < %d LIMIT 1",
            (string) $session_id,
            $message_id
        ));
    }

    /**
     * Push keys of the paired phones that want $event: push key => device id. A phone whose WordPress account
     * is no longer an administrator is left out (its token is refused too, MxChat_App_Auth::identify()).
     */
    public static function recipients($event) {
        $setting = self::EVENT_SETTING[$event] ?? '';
        // An update caught half-way (an opcode cache still serving the older auth class) sends nothing.
        if ($setting === '' || !method_exists('MxChat_App_Auth', 'push_of')) {
            return array();
        }
        $keys = array();
        foreach (MxChat_App_Auth::devices() as $id => $device) {
            if (!is_array($device)) {
                continue;
            }
            $push = MxChat_App_Auth::push_of($device);
            if ($push['key'] === '' || empty($push['events'][$setting])) {
                continue;
            }
            if (!user_can((int) ($device['user_id'] ?? 0), 'manage_options')) {
                continue;
            }
            $keys[$push['key']] = (string) $id;
        }
        return $keys;
    }

    // ------------------------------------------------------------------
    // Sending
    // ------------------------------------------------------------------

    private static function queue($event, $session_id) {
        if (!self::$queue) {
            add_action('shutdown', array(__CLASS__, 'flush'), PHP_INT_MAX);
        }
        self::$queue[$event . '|' . $session_id] = array('event' => $event, 'session_id' => (string) $session_id);
    }

    /** What is waiting for shutdown (rigs). */
    public static function queued() {
        return array_values(self::$queue);
    }

    /** shutdown, last: one relay request per event (20 keys each), after the visitor has the whole reply. */
    public static function flush() {
        if (!self::$queue) {
            return;
        }
        $queue       = self::$queue;
        self::$queue = array();
        $detached    = self::finish_request();
        foreach ($queue as $item) {
            $keys = array_keys(self::recipients($item['event']));
            foreach (array_chunk($keys, self::KEYS_PER_REQUEST) as $chunk) {
                self::send($item['event'], $item['session_id'], $chunk, $detached, $detached ? 3 : 1);
            }
        }
    }

    /**
     * Hand the response to the client and close the connection, so the relay request cannot hold it open.
     * WordPress has already flushed its output buffers on shutdown (wp_ob_end_flush_all, priority 1), and
     * both functions flush whatever is left before closing, so a reply (streamed or not) arrives complete.
     */
    private static function finish_request() {
        if (function_exists('litespeed_finish_request')) {
            ignore_user_abort(true);
            litespeed_finish_request();
            return true;
        }
        if (function_exists('fastcgi_finish_request')) {
            ignore_user_abort(true);
            fastcgi_finish_request();
            return true;
        }
        return false;
    }

    /**
     * One request to the relay. Waiting for the answer: the relay's result per key, in key order, and a key
     * it no longer knows ("gone", "unknown") is cleared from its phone. Not waiting: null.
     *
     * @return string[]|null
     */
    public static function send($event, $session_id, array $keys, $blocking = true, $timeout = 3) {
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys), array('MxChat_App_Auth', 'valid_push_key'))));
        if (!$keys) {
            return array();
        }
        $response = wp_remote_post(self::relay_url(), array(
            'timeout'     => $timeout,
            'blocking'    => (bool) $blocking,
            'redirection' => 0,
            'user-agent'  => 'MxChat/' . (defined('MXCHAT_VERSION') ? MXCHAT_VERSION : ''),
            'headers'     => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
            'body'        => wp_json_encode(array(
                'push_keys'  => $keys,
                'event'      => (string) $event,
                'session_id' => (string) $session_id,
            )),
            'data_format' => 'body',
        ));
        if (!$blocking) {
            self::log($event, count($keys), $response, null);
            return null;
        }
        $results = self::results($response, count($keys));
        foreach ($keys as $i => $key) {
            if ($results[$i] === 'gone' || $results[$i] === 'unknown') {
                MxChat_App_Auth::clear_push_key($key);
            }
        }
        self::log($event, count($keys), $response, $results);
        return $results;
    }

    /** The relay's 200 {"results": [...]} read in key order; anything else counts as "error" (429: "rate_limited"). */
    private static function results($response, $count) {
        if (is_wp_error($response)) {
            return array_fill(0, $count, 'error');
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 429) {
            return array_fill(0, $count, 'rate_limited');
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $list = ($status === 200 && is_array($body) && isset($body['results']) && is_array($body['results'])) ? array_values($body['results']) : array();
        $out  = array();
        for ($i = 0; $i < $count; $i++) {
            $result = isset($list[$i]) && is_string($list[$i]) ? $list[$i] : 'error';
            $out[]  = in_array($result, self::RESULTS, true) ? $result : 'error';
        }
        return $out;
    }

    /** POST /app/devices/push/test: a "test" push to one phone, waiting up to 8 s for the relay's answer. */
    public static function test($device_id) {
        if (!method_exists('MxChat_App_Auth', 'push_of')) {
            return 'error';
        }
        $devices = MxChat_App_Auth::devices();
        $push    = isset($devices[$device_id]) && is_array($devices[$device_id]) ? MxChat_App_Auth::push_of($devices[$device_id]) : array('key' => '');
        if ($push['key'] === '') {
            return 'no_key';
        }
        if (!self::enabled()) {
            return 'error';
        }
        $results = self::send('test', '', array($push['key']), true, 8);
        return $results ? $results[0] : 'error';
    }

    /** Dev mode only: one line per relay request (event, phone count, the relay's answer; no keys, no chat). */
    private static function log($event, $count, $response, $results) {
        if (!defined('MXCHAT_DEV_MODE') || !MXCHAT_DEV_MODE) {
            return;
        }
        $status = is_wp_error($response) ? $response->get_error_code() : (string) wp_remote_retrieve_response_code($response);
        error_log(sprintf(
            'MxChat app push: %s to %d phone(s), %s, relay %s%s',
            $event,
            $count,
            $results === null ? 'not waited' : 'waited',
            $status === '' ? '-' : $status,
            $results === null ? '' : ' ' . wp_json_encode($results)
        ));
    }
}

add_action('mxchat_app_message_saved', array('MxChat_App_Push', 'on_message_saved'), 10, 3);
add_action('mxchat_app_handoff_requested', array('MxChat_App_Push', 'on_handoff'), 10, 2);
add_action('mxchat_app_lead_captured', array('MxChat_App_Push', 'on_lead'), 10, 1);
