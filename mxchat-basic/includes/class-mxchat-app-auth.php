<?php
/**
 * MxChat app sign-in: phones paired by QR code, each with its own revocable token.
 *
 * MxChat → API Access → Mobile app shows a single-use pairing code (5 minutes) as a QR code and as an
 * "Open in the MxChat app" link. The app trades the code for a device token tied to the WordPress user
 * who showed the code; the site keeps only a hash of it. Every app request then runs as that user, and
 * the phone can be cut off on its own from the Paired phones list without touching the site API token
 * (which integrations keep using, and which the app still accepts as a fallback).
 *
 * Tokens are read from `Authorization: Bearer` or from `X-MxChat-Token`: some hosts drop the
 * Authorization header before PHP sees it, and the app sends both.
 *
 * @package MxChat
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_App_Auth {

    const DEVICES_OPTION = 'mxchat_app_devices';
    /** Last use per phone, kept apart from the list so a busy phone never writes the list back (see touch()). */
    const SEEN_OPTION    = 'mxchat_app_devices_seen';
    const CODE_TRANSIENT = 'mxchat_app_pair_';
    const CODE_TTL       = 300;
    const TOKEN_PREFIX   = 'mxd_';

    /**
     * Whether wp-admin shows the app (API Access → Mobile app: the tab, its QR code and pairing script). Off by
     * default while the app is not public; the app's routes, pairing included, work either way. On with
     * `wp option update mxchat_app_preview 1`, or the mxchat_app_preview filter (default: the option).
     */
    public static function preview_enabled() {
        $option = get_option('mxchat_app_preview', '0');
        return (bool) apply_filters('mxchat_app_preview', in_array($option, array('1', 1, true, 'yes', 'on'), true));
    }

    /** The token a request carries, from either header. */
    public static function request_token($request) {
        $auth = $request->get_header('authorization');
        if (is_string($auth) && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        $alt = $request->get_header('x_mxchat_token');
        return is_string($alt) ? trim($alt) : '';
    }

    /**
     * Who a request is: the site API token (acts as the first administrator) or a paired phone (acts as the
     * user who paired it). Error codes match MxChat_Rest_Api::check_bearer_token() so clients read them the same.
     *
     * @return array{kind:string,user_id:int,device_id:string}|WP_Error
     */
    public static function identify($request) {
        $provided   = self::request_token($request);
        $site_token = (string) get_option('mxchat_api_token', '');
        if ($provided === '') {
            if ($site_token === '' && empty(self::devices())) {
                return new WP_Error('mxchat_rest_disabled', __('MxChat REST API is disabled. Generate a token from MxChat → API Access in the WordPress admin.', 'mxchat'), array('status' => 401));
            }
            return new WP_Error('mxchat_rest_no_auth', __('Missing Authorization header. Expected: Authorization: Bearer <token>', 'mxchat'), array('status' => 401));
        }
        if (strpos($provided, self::TOKEN_PREFIX) === 0) {
            $device = self::device_for_token($provided);
            if (!$device) {
                return new WP_Error('mxchat_rest_bad_token', __('This phone is no longer paired with the site. Pair it again from MxChat → API Access → Mobile app.', 'mxchat'), array('status' => 401));
            }
            if (!user_can((int) $device['user_id'], 'manage_options')) {
                return new WP_Error('mxchat_app_not_admin', __('The WordPress account this phone was paired with is no longer an administrator.', 'mxchat'), array('status' => 403));
            }
            self::touch($device['id']);
            return array('kind' => 'device', 'user_id' => (int) $device['user_id'], 'device_id' => (string) $device['id']);
        }
        if ($site_token === '') {
            return new WP_Error('mxchat_rest_disabled', __('MxChat REST API is disabled. Generate a token from MxChat → API Access in the WordPress admin.', 'mxchat'), array('status' => 401));
        }
        if (!hash_equals($site_token, $provided)) {
            return new WP_Error('mxchat_rest_bad_token', __('Invalid API token.', 'mxchat'), array('status' => 401));
        }
        return array('kind' => 'site', 'user_id' => 0, 'device_id' => '');
    }

    // ------------------------------------------------------------------
    // Devices
    // ------------------------------------------------------------------

    /** @return array<string,array> device id => device (the token itself is never stored, only its hash) */
    public static function devices() {
        $devices = get_option(self::DEVICES_OPTION, array());
        return is_array($devices) ? $devices : array();
    }

    private static function save_devices(array $devices) {
        update_option(self::DEVICES_OPTION, $devices, false);
    }

    public static function device_for_token($token) {
        $hash = hash('sha256', (string) $token);
        foreach (self::devices() as $device) {
            if (!empty($device['hash']) && hash_equals((string) $device['hash'], $hash)) {
                return $device;
            }
        }
        return null;
    }

    private static function seen() {
        $seen = get_option(self::SEEN_OPTION, array());
        return is_array($seen) ? $seen : array();
    }

    /**
     * Last use, written at most once a minute so a polling app does not rewrite an option every request.
     * It goes to its own option: writing the devices list here would put back a phone that another request
     * revoked after this one had read the list.
     */
    private static function touch($device_id) {
        $seen = self::seen();
        if (time() - (int) ($seen[$device_id] ?? 0) < MINUTE_IN_SECONDS) {
            return;
        }
        $seen[$device_id] = time();
        update_option(self::SEEN_OPTION, array_intersect_key($seen, self::devices()), false);
    }

    public static function revoke($device_id) {
        $devices = self::devices();
        if (!isset($devices[$device_id])) {
            return false;
        }
        unset($devices[$device_id]);
        self::save_devices($devices);
        $seen = self::seen();
        if (isset($seen[$device_id])) {
            unset($seen[$device_id]);
            update_option(self::SEEN_OPTION, $seen, false);
        }
        return true;
    }

    /** A deleted WordPress account's phones leave the list (they already stop working: identify() checks the account). */
    public static function forget_user($user_id) {
        foreach (self::devices() as $id => $device) {
            if ((int) ($device['user_id'] ?? 0) === (int) $user_id) {
                self::revoke($id);
            }
        }
    }

    /** For the app and the admin list: no hashes. */
    public static function public_device(array $device) {
        $user = get_userdata((int) $device['user_id']);
        return array(
            'id'        => (string) $device['id'],
            'name'      => (string) $device['name'],
            'platform'  => (string) $device['platform'],
            'user'      => $user ? $user->display_name : '',
            // false once that account is deleted or no longer an administrator: the phone is refused (identify()).
            'active'    => $user && user_can($user, 'manage_options'),
            'created'   => (int) $device['created'],
            'last_seen' => max((int) ($device['last_seen'] ?? 0), (int) (self::seen()[$device['id']] ?? 0)),
        );
    }

    // ------------------------------------------------------------------
    // Pairing
    // ------------------------------------------------------------------

    /** A single-use code for the current user, valid for five minutes. */
    public static function create_code($user_id) {
        $code = wp_generate_password(24, false, false);
        set_transient(self::CODE_TRANSIENT . hash('sha256', $code), array('user_id' => (int) $user_id, 'created' => time()), self::CODE_TTL);
        return array(
            'code'    => $code,
            'expires' => time() + self::CODE_TTL,
            'ttl'     => self::CODE_TTL,
            'link'    => self::pair_link($code),
        );
    }

    /** What the QR code holds; on a phone with the app installed it opens the app straight into pairing. */
    public static function pair_link($code) {
        return 'mxchatapp://pair?site=' . rawurlencode(home_url('/')) . '&code=' . rawurlencode($code);
    }

    /**
     * Trade a pairing code for a device token. Codes are single use; a bad or expired code counts toward a
     * per-address limit (10 per 10 minutes) so codes cannot be guessed.
     *
     * @return array|WP_Error
     */
    public static function pair($code, $name, $platform) {
        $ip_key = 'mxchat_app_pair_tries_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $tries  = (int) get_transient($ip_key);
        if ($tries >= 10) {
            return new WP_Error('mxchat_app_pair_limited', __('Too many pairing attempts. Wait a few minutes and scan a new code.', 'mxchat'), array('status' => 429));
        }
        $key     = self::CODE_TRANSIENT . hash('sha256', (string) $code);
        $data    = get_transient($key);
        $expired = new WP_Error('mxchat_app_pair_expired', __('That pairing code has expired or was already used. Show a new one in MxChat → API Access → Mobile app.', 'mxchat'), array('status' => 400));
        // Five minutes from when the code was shown, whatever the store's own expiry does (an object cache
        // that keeps entries past their time would otherwise keep the code alive).
        $fresh = is_array($data) && !empty($data['user_id']) && time() - (int) ($data['created'] ?? 0) <= self::CODE_TTL;
        if (!$fresh) {
            if (is_array($data)) {
                delete_transient($key);
            }
            set_transient($ip_key, $tries + 1, 10 * MINUTE_IN_SECONDS);
            return $expired;
        }
        // Single use when two requests read the code at the same moment: only the request whose delete
        // removed it goes on (the database delete and the object caches both report whether it existed).
        if (!delete_transient($key)) {
            return $expired;
        }
        if (!user_can((int) $data['user_id'], 'manage_options')) {
            return new WP_Error('mxchat_app_not_admin', __('Only administrators can pair a phone.', 'mxchat'), array('status' => 403));
        }

        $token  = self::TOKEN_PREFIX . wp_generate_password(44, false, false);
        $id     = wp_generate_password(12, false, false);
        $device = array(
            'id'        => $id,
            'user_id'   => (int) $data['user_id'],
            'name'      => wp_html_excerpt(sanitize_text_field((string) $name), 60, '') ?: __('Phone', 'mxchat'),
            'platform'  => sanitize_key((string) $platform) ?: 'android',
            'hash'      => hash('sha256', $token),
            'created'   => time(),
            'last_seen' => time(),
        );
        $devices      = self::devices();
        $devices[$id] = $device;
        self::save_devices($devices);

        $user = get_userdata((int) $data['user_id']);
        return array(
            'token'     => $token,
            'device_id' => $id,
            'site'      => array('name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), 'url' => home_url('/')),
            'user'      => array('name' => $user ? $user->display_name : ''),
        );
    }

    // ------------------------------------------------------------------
    // wp-admin: API Access → Mobile app
    // ------------------------------------------------------------------

    public static function init_admin() {
        add_action('wp_ajax_mxchat_app_pair_code', array(__CLASS__, 'ajax_pair_code'));
        add_action('wp_ajax_mxchat_app_devices', array(__CLASS__, 'ajax_devices'));
        add_action('wp_ajax_mxchat_app_revoke_device', array(__CLASS__, 'ajax_revoke'));
    }

    public static function ajax_pair_code() {
        check_ajax_referer('mxchat_app_pairing', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mxchat')), 403);
        }
        wp_send_json_success(self::create_code(get_current_user_id()));
    }

    public static function ajax_devices() {
        check_ajax_referer('mxchat_app_pairing', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mxchat')), 403);
        }
        wp_send_json_success(array('devices' => array_values(array_map(array(__CLASS__, 'public_device'), self::devices()))));
    }

    public static function ajax_revoke() {
        check_ajax_referer('mxchat_app_pairing', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'mxchat')), 403);
        }
        $id = sanitize_text_field(wp_unslash($_POST['device_id'] ?? ''));
        wp_send_json_success(array('revoked' => self::revoke($id)));
    }
}

add_action('deleted_user', array('MxChat_App_Auth', 'forget_user'));
